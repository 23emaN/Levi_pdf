<?php

namespace App;

use PDO;
use InvalidArgumentException;

/**
 * Service to repair multi-page PDF invoice suffix anomalies,
 * strip duplicate footers from non-terminal continuation pages,
 * and split master PDF files into individual invoice PDFs in finished/.
 */
class PdfFixer
{
    /** The answer to fixing one bill that waits for the ASN (the user's wording) */
    public const WAITS_FOR_ASN = 'บิลนี้รอแก้ไข ASN แก้ไข PDF ไม่ได้ ต้องแก้ ASN แล้วนำเข้าใหม่ก่อน';

    /** @var PDO */
    private $db;

    /** @var PdfSplitter */
    private $splitter;

    public function __construct(PDO $db, ?PdfSplitter $splitter = null)
    {
        $this->db = $db;
        $this->splitter = $splitter ?? new PdfSplitter();
    }

    /**
     * Fix pending invoices for a job (or a specific invoice). A bill that waits for the ASN is not fixed: asked for alone
     * the answer is success false with WAITS_FOR_ASN, and in a Job that holds one only the files holding a bill whose PDF
     * waits for the fix are fixed.
     *
     * @param int $jobId
     * @param string|null $targetInvoice Specific invoice to fix, or null for all pending in job
     * @return array Summary of actions performed
     */
    public function fixJob(int $jobId, ?string $targetInvoice = null): array
    {
        $stmtJob = $this->db->prepare("SELECT id, name FROM jobs WHERE id = :id");
        $stmtJob->execute([':id' => $jobId]);
        $job = $stmtJob->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new InvalidArgumentException("Job ID {$jobId} not found.");
        }

        $finishedDir = (new JobManager())->getJobFinishedDir($job, false);

        // 1. Load ASN records for this job
        $stmtAsn = $this->db->prepare("
            SELECT invoice_no, ean_upc, quantity, total_cost_ex_vat, total_vat
            FROM job_asn_records
            WHERE job_id = :job_id
        ");
        $stmtAsn->execute([':job_id' => $jobId]);
        $asnRows = $stmtAsn->fetchAll(PDO::FETCH_ASSOC);

        if (empty($asnRows)) {
            return [
                'success' => false,
                'message' => 'ไม่พบข้อมูล ASN ใน Job นี้ ไม่สามารถเทียบเพื่อปรับข้อมูลได้'
            ];
        }

        // 2. Load PDF items for this job
        $stmtItems = $this->db->prepare("
            SELECT id, filename, page_no, tax_invoice, total_shipped, total_amount,
                   amt_excl_vat, vat_amount, barcode, units
            FROM job_items
            WHERE job_id = :job_id
            ORDER BY id ASC
        ");
        $stmtItems->execute([':job_id' => $jobId]);
        $pdfItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pdfItems)) {
            return [
                'success' => false,
                'message' => 'ไม่พบข้อมูล PDF ใน Job นี้'
            ];
        }

        $plan = new FixPlan($asnRows, $pdfItems);
        $reconciler = new AsnReconciler($this->db);
        $statusBefore = AsnReconciler::invoiceStatuses($reconciler->reconcile($jobId, $this->fetchAllItems($jobId), $job)['results']);

        $cleanTarget = $targetInvoice === null ? null : self::cleanInvoice($targetInvoice);
        $waitingPdf = [];
        foreach ($statusBefore as $invoice => $status) {
            if ($cleanTarget === self::cleanInvoice((string)$invoice) && $status === BillStatus::PENDING_ASN) {
                return ['success' => false, 'message' => self::WAITS_FOR_ASN];
            }
            if (BillStatus::canFix($status)) {
                $waitingPdf[] = self::cleanInvoice((string)$invoice);
            }
        }
        // With a bill that waits for the ASN only the files holding a bill whose PDF waits are fixed: a file is fixed as
        // a whole, so a bill that waits for the ASN in such a file is fixed with it and still waits for the ASN
        $onlyWaitingFiles = in_array(BillStatus::PENDING_ASN, $statusBefore, true);

        $fixedInvoicesCount = 0;
        $splitFilesCreated = [];
        $updatedItemIds = [];
        $clearedFooterItemIds = [];
        $log = new FixLog($this->db);
        $touched = [];

        $stmtUpdateInv = $this->db->prepare("UPDATE job_items SET tax_invoice = :inv WHERE id = :id");
        $footer = [];
        foreach (FixPlan::CLEARED_FOOTER as $field => $value) {
            $footer[] = $field . ' = ' . $this->db->quote($value);
        }
        $stmtClearFooter = $this->db->prepare('UPDATE job_items SET ' . implode(', ', $footer) . ' WHERE id = :id');

        foreach ($plan->files() as $filename => $file) {
            $held = array_map([self::class, 'cleanInvoice'], array_map('strval', array_keys($file['invoices'])));
            if ($cleanTarget !== null && !in_array($cleanTarget, $held, true)) {
                continue;
            }
            if ($onlyWaitingFiles && empty(array_intersect($held, $waitingPdf))) {
                continue;
            }

            if (!empty($file['steps'])) {
                $this->db->beginTransaction();
                try {
                    foreach ($file['pages'] as $page) {
                        foreach ($page['renumber'] as $id) {
                            $stmtUpdateInv->execute([':inv' => $page['invoice'], ':id' => $id]);
                            $updatedItemIds[] = $id;
                        }
                        foreach ($page['clear_footer'] as $id) {
                            $stmtClearFooter->execute([':id' => $id]);
                            $clearedFooterItemIds[] = $id;
                        }
                    }
                    $log->record($jobId, $file['steps']);
                    $touched = array_merge($touched, FixLog::invoicesTouched($file['steps']));
                    $this->db->commit();
                } catch (\Exception $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            }

            if (empty($file['split'])) {
                continue;
            }
            $sourcePdfPath = $finishedDir . DIRECTORY_SEPARATOR . $filename;
            if (!file_exists($sourcePdfPath)) {
                continue;
            }

            $steps = [];
            foreach ($file['split'] as $inv => $pList) {
                $targetSplitPath = dirname($sourcePdfPath) . DIRECTORY_SEPARATOR . $inv . '.pdf';
                try {
                    $this->splitter->extractInvoice($sourcePdfPath, $pList, $inv, $targetSplitPath);
                    $splitFilesCreated[] = basename($targetSplitPath);
                    $fixedInvoicesCount++;
                    $steps = array_merge($steps, FixPlan::splitSteps($filename, $inv, $pList));
                } catch (\Exception $e) {
                    error_log("PdfFixer warning splitting {$inv}: " . $e->getMessage());
                }
            }
            $log->record($jobId, $steps);
            $touched = array_merge($touched, FixLog::invoicesTouched($steps));
        }

        // 4. Re-run reconciliation
        $reconResult = $reconciler->reconcile($jobId, $this->fetchAllItems($jobId), $job);
        $fixedInvoiceNos = $this->recordFixedInvoices($jobId, $statusBefore, AsnReconciler::invoiceStatuses($reconResult['results']), $touched);

        return [
            'success' => true,
            'fixed_invoice_numbers' => count($fixedInvoiceNos),
            'fixed_invoices_count' => $fixedInvoicesCount,
            'updated_items_count' => count($updatedItemIds),
            'cleared_footers_count' => count($clearedFooterItemIds),
            'split_files_created' => $splitFilesCreated,
            'reconcile_summary' => $reconResult['summary'] ?? null
        ];
    }

    private static function cleanInvoice(string $invoice): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9\-]/', '', $invoice));
    }

    private function fetchAllItems(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM job_items WHERE job_id = :job_id ORDER BY id ASC");
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array $before invoice number => status before the run
     * @param array $after invoice number => status after the run
     * @param string[] $touched invoices the steps of this run did something to
     * @return string[] invoice numbers recorded by this run
     */
    private function recordFixedInvoices(int $jobId, array $before, array $after, array $touched): array
    {
        $improved = array_fill_keys($touched, true);
        foreach ($before as $invoice => $was) {
            $now = $after[$invoice] ?? $was;
            if (($was === BillStatus::PENDING_FIX && $now !== BillStatus::PENDING_FIX) || ($was === BillStatus::MISMATCH && $now === BillStatus::MATCH)) {
                $improved[(string)$invoice] = true;
            }
        }
        $improved = array_map('strval', array_keys($improved));
        if (empty($improved)) {
            return [];
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        $stmt = $this->db->prepare("INSERT OR IGNORE INTO job_invoice_fixes (job_id, invoice_no) VALUES (:job_id, :invoice)");
        foreach ($improved as $invoice) {
            $stmt->execute([':job_id' => $jobId, ':invoice' => $invoice]);
        }
        if ($ownTx) {
            $this->db->commit();
        }
        return $improved;
    }
}
