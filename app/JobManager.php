<?php

namespace App;

class JobManager
{
    /** @var JobFolders */
    private $folders;

    /** @var JobRepository */
    private $jobs;

    /** @var PdfItemStore */
    private $items;

    /** @var AsnStore */
    private $asn;

    /** @var DashboardBuilder */
    private $dashboard;

    /** @var CreditNoteStore */
    private $creditNotes;

    /** @var CreditNoteAsnStore */
    private $creditNoteAsn;

    /** @var CreditNoteCompare */
    private $creditNoteCompare;

    /** @var VbrpStore */
    private $vbrp;

    /** @var BillHistory */
    private $history;

    public $lastAsnImport = AsnStore::NO_IMPORT;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->folders = new JobFolders();
        $this->jobs = new JobRepository($db, $this->folders);
        $this->items = new PdfItemStore($db, $this->folders, $this->jobs);
        $this->asn = new AsnStore($db, $this->jobs);
        $this->history = new BillHistory($db);
        $this->dashboard = new DashboardBuilder($db, $this->jobs, $this->items, $this->asn, $this->history);
        $this->creditNotes = new CreditNoteStore($db, $this->folders, $this->jobs);
        $this->creditNoteAsn = new CreditNoteAsnStore($db);
        $this->creditNoteCompare = new CreditNoteCompare($this->creditNoteAsn, $this->creditNotes, $this->jobs);
        $this->vbrp = new VbrpStore($db, $this->folders, $this->jobs);
    }

    public function listJobs(): array
    {
        return $this->jobs->listJobs();
    }

    public function getJob(int $jobId): ?array
    {
        return $this->jobs->getJob($jobId);
    }

    /**
     * A Job with the same folder name whose folder is gone no longer exists for the user, so it is deleted
     * to make way for the new one
     */
    public function createJob(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Job ' . date('Y-m-d H:i');
        }

        $folderKey = strtolower($this->folders->sanitizeFolderName($name));
        foreach ($this->jobs->allJobNames() as $existing) {
            if (strtolower($this->folders->sanitizeFolderName($existing['name'], (int)$existing['id'])) === $folderKey) {
                if ($this->folders->jobFolderExists($existing)) {
                    throw new \RuntimeException('มี Job ชื่อ "' . $existing['name'] . '" อยู่แล้ว กรุณาใช้ชื่ออื่น');
                }
                $this->deleteJob((int)$existing['id']);
            }
        }

        return $this->jobs->insertJob($name);
    }

    public function deleteJob(int $jobId): bool
    {
        $job = $this->jobs->getJob($jobId);
        $this->clearJobData($jobId);
        $this->jobs->deleteJobRow($jobId);
        $this->folders->deleteJobFolders($jobId, $job);
        return true;
    }

    public function clearJobData(int $jobId): bool
    {
        $this->items->deleteJobPdfData($jobId);
        $this->asn->deleteJobAsnRecords($jobId);
        $this->history->clear($jobId);
        $this->jobs->resetJobTotals($jobId);

        $job = $this->jobs->getJob($jobId);
        if ($job) {
            $this->vbrp->clear($jobId);
            $this->folders->removeProcessedJobFolder($job);
        }

        return true;
    }

    public function deleteSummary(int $jobId): ?array
    {
        $job = $this->jobs->getJob($jobId);
        if (!$job) {
            return null;
        }
        $asn = $this->asn->asnCounts($jobId);
        $pdf = $this->items->storedCounts($jobId);
        $cn = $this->creditNotes->counts($jobId);
        return [
            'name' => $job['name'],
            'pdf_files' => $pdf['files'],
            'pdf_rows' => $pdf['rows'],
            'upload_files' => count(FileManager::getPdfFiles($this->folders->getJobUploadDir($job, false))),
            'asn_files' => $asn['files'],
            'asn_rows' => $asn['rows'],
            'print_files' => FileManager::countFiles($this->folders->getJobPrintDir($job, false)),
            'print_rounds' => (new PrintManager($this))->roundCount($jobId),
            'fixed_bills' => $pdf['fixed_bills'],
            'cn_files' => $cn['files'],
            'cn_documents' => $cn['documents'],
            'cn_asn_rows' => $this->creditNoteAsn->counts($jobId)['rows'],
            'vbrp_rows' => $this->vbrp->counts($jobId)['rows'],
        ];
    }

    public function statusCounts(int $jobId): array
    {
        $pdf = $this->items->itemCounts($jobId);
        $asn = $this->asn->asnCounts($jobId);
        $vbrp = $this->vbrp->counts($jobId);
        return [
            'pdf_files' => $pdf['files'], 'pdf_rows' => $pdf['rows'], 'asn_files' => $asn['files'], 'asn_rows' => $asn['rows'],
            'vbrp_files' => $vbrp['files'], 'vbrp_rows' => $vbrp['rows'], 'vbrp_invoices' => $vbrp['invoices'],
        ];
    }

    public function initialAsnStep(array $job): string
    {
        return $this->jobs->initialAsnStep($job, $this->vbrp->counts((int)($job['id'] ?? 0))['rows'] > 0);
    }

    public function updateJobStats(int $jobId): void
    {
        $this->jobs->updateJobStats($jobId);
    }

    public function sanitizeFolderName(string $name, int $jobId = 0): string
    {
        return $this->folders->sanitizeFolderName($name, $jobId);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobFolderName($jobOrId): string
    {
        return $this->folders->getJobFolderName($this->folderJob($jobOrId));
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobFolderDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobFolderDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobTaxInvoiceDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobTaxInvoiceDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobUploadDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobUploadDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobFinishedDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobFinishedDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobPrintDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobPrintDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobPrintRoundDir($jobOrId, int $round): string
    {
        return $this->folders->getJobPrintRoundDir($this->folderJob($jobOrId), $round);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobCreditNoteDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobCreditNoteDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobCnUploadDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobCnUploadDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobCnFinishedDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobCnFinishedDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobCoverDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobCoverDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function jobFolderExists($jobOrId): bool
    {
        return $this->folders->jobFolderExists($this->folderJob($jobOrId));
    }

    public function deleteDirectoryRecursive(string $dir): bool
    {
        return $this->folders->deleteDirectoryRecursive($dir);
    }

    /**
     * The Job row the folder methods work on. An id the jobs table does not hold keeps the folder name job_{id}
     * (sanitizeFolderName leaves that name as it is).
     *
     * @param array|int $jobOrId
     */
    private function folderJob($jobOrId): array
    {
        $job = is_array($jobOrId) ? $jobOrId : $this->jobs->getJob((int)$jobOrId);
        return $job ?: ['id' => (int)$jobOrId, 'name' => 'job_' . (int)$jobOrId];
    }

    public function scanSourceFiles(int $jobId): array
    {
        return $this->items->scanSourceFiles($jobId);
    }

    public function existingInvoiceRoots(int $jobId): array
    {
        return $this->items->existingInvoiceRoots($jobId);
    }

    public function getJobItems(int $jobId): array
    {
        return $this->items->getJobItems($jobId);
    }

    public function recordExtractedFile(int $jobId, string $filename, string $storedPath, array &$items, bool $overwrite = false, bool $deferStats = false): void
    {
        $this->items->recordExtractedFile($jobId, $filename, $storedPath, $items, $overwrite, $deferStats);
    }

    public function recordExtractedBatch(int $jobId, array &$batchEntries, bool $overwrite = false, array $removeFilenames = []): array
    {
        return $this->items->recordExtractedBatch($jobId, $batchEntries, $overwrite, $removeFilenames);
    }

    public function deleteFileRecords(int $jobId, string $filename): void
    {
        $this->items->deleteFileRecords($jobId, $filename);
    }

    public function deleteJobItems(int $jobId, array $itemIds): int
    {
        return $this->items->deleteJobItems($jobId, $itemIds);
    }

    public function scanCreditNotes(int $jobId): array
    {
        return $this->creditNotes->scan($jobId);
    }

    public function extractCreditNotes(int $jobId, array $relPaths, bool $replaceDuplicates): array
    {
        return $this->creditNotes->extractBatch($jobId, $relPaths, $replaceDuplicates);
    }

    public function getCreditNotes(int $jobId): array
    {
        return $this->creditNotes->docs($jobId);
    }

    /**
     * The credit note ASN rows stay (they are not PDF data); the compare made on the documents goes with them
     */
    public function clearCreditNotes(int $jobId): array
    {
        $cleared = $this->creditNotes->clear($jobId);
        $this->creditNoteAsn->setReconciled($jobId, false);
        return $cleared;
    }

    public function creditNoteCounts(int $jobId): array
    {
        return $this->creditNotes->counts($jobId) + ['asn' => $this->creditNoteAsn->counts($jobId)];
    }

    public function creditNoteCompare(): CreditNoteCompare
    {
        return $this->creditNoteCompare;
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobVbrpDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobVbrpDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobVbrpUploadDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobVbrpUploadDir($this->folderJob($jobOrId), $autoCreate);
    }

    /**
     * @param array|int $jobOrId
     */
    public function getJobVbrpFinishedDir($jobOrId, bool $autoCreate = true): string
    {
        return $this->folders->getJobVbrpFinishedDir($this->folderJob($jobOrId), $autoCreate);
    }

    public function scanVbrp(int $jobId): array
    {
        return $this->vbrp->scan($jobId);
    }

    public function extractVbrp(int $jobId, array $relPaths, bool $replaceExisting = true): array
    {
        return $this->vbrp->extractBatch($jobId, $relPaths, $replaceExisting);
    }

    public function getVbrpRows(int $jobId): array
    {
        return $this->vbrp->rows($jobId);
    }

    public function getVbrpFiles(int $jobId): array
    {
        return $this->vbrp->files($jobId);
    }

    public function clearVbrp(int $jobId): array
    {
        return $this->vbrp->clear($jobId);
    }

    public function vbrpCounts(int $jobId): array
    {
        return $this->vbrp->counts($jobId);
    }

    public function saveVbrpUploads(int $jobId, array $files): array
    {
        return $this->vbrp->saveUploads($jobId, $files);
    }

    public function importVbrp(int $jobId, string $tmpPath, string $originalName, int $uploadError = UPLOAD_ERR_OK): array
    {
        return $this->vbrp->importUpload($jobId, $tmpPath, $originalName, $uploadError);
    }

    public function asnCounts(int $jobId): array
    {
        return $this->asn->asnCounts($jobId);
    }

    public function getAsnRecords(int $jobId): array
    {
        return $this->asn->getAsnRecords($jobId);
    }

    public function findAsnOverlap(int $jobId, string $sourceFile, array $invoiceNos): array
    {
        return $this->asn->findAsnOverlap($jobId, $sourceFile, $invoiceNos);
    }

    public function describeStoredAsnFile(int $jobId, string $sourceFile, array $incomingRows): array
    {
        return $this->asn->describeStoredAsnFile($jobId, $sourceFile, $incomingRows);
    }

    public function saveAsnRecords(int $jobId, string $asnFilename, array $rows, bool $append = true, string $duplicateMode = 'skip', string $sameFileMode = 'replace'): int
    {
        try {
            return $this->asn->saveAsnRecords($jobId, $asnFilename, $rows, $append, $duplicateMode, $sameFileMode);
        } finally {
            $this->lastAsnImport = $this->asn->lastAsnImport;
        }
    }

    public function deleteAsnRecords(int $jobId, array $asnIds): int
    {
        return $this->asn->deleteAsnRecords($jobId, $asnIds);
    }

    public function refreshAsnMetadata(int $jobId): ?string
    {
        return $this->asn->refreshAsnMetadata($jobId);
    }

    public function reconcileJob(int $jobId): array
    {
        return $this->dashboard->reconcileJob($jobId, new PrintManager($this));
    }

    public function getInvoiceDashboard(int $jobId): array
    {
        return $this->dashboard->getInvoiceDashboard($jobId);
    }

    /**
     * After a user action (BillHistory::EVENTS): one history row per bill whose category changed since its last row, or
     * that has none yet, from one reconcile of the Job. A Job not compared records nothing, except on the compare itself.
     *
     * @return int rows written
     */
    public function recordBillHistory(int $jobId, string $event): int
    {
        $job = $this->jobs->getJob($jobId);
        if (!$job || (empty($job['asn_reconciled']) && $event !== 'compare')) {
            return 0;
        }
        return $this->history->record($jobId, $event, $this->dashboard->billCategories($jobId));
    }

    public static function invoiceRoot(string $invoice): string
    {
        return PdfItemStore::invoiceRoot($invoice);
    }

    public static function invoiceKey(string $invoice): string
    {
        return DashboardBuilder::invoiceKey($invoice);
    }
}
