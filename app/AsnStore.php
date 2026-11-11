<?php

namespace App;

use PDO;

/**
 * The ASN file label and the "compared" flag of the Job row change with the rows, through JobRepository.
 */
class AsnStore
{
    /** @var PDO */
    private $db;

    /** @var JobRepository */
    private $jobs;

    const NO_IMPORT = ['skipped_invoices' => 0, 'replaced_invoices' => 0, 'skipped_same_file' => false, 'replaced_same_file' => false];

    public $lastAsnImport = self::NO_IMPORT;

    public function __construct(PDO $db, JobRepository $jobs)
    {
        $this->db = $db;
        $this->jobs = $jobs;
    }

    /**
     * ASN rows of a Job and the files they came from; rows without a file name count as one file
     */
    public function asnCounts(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT TRIM(COALESCE(source_file, ''))) AS file_count, COUNT(*) AS row_count FROM job_asn_records WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['files' => (int)$row['file_count'], 'rows' => (int)$row['row_count']];
    }

    public function invoiceCount(int $jobId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT TRIM(invoice_no)) FROM job_asn_records WHERE job_id = :job_id AND TRIM(invoice_no) != ''");
        $stmt->execute([':job_id' => $jobId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Invoices of an incoming ASN file that another ASN file of the Job already holds
     *
     * @return array invoice => [source files]
     */
    public function findAsnOverlap(int $jobId, string $sourceFile, array $invoiceNos): array
    {
        $wanted = array_flip(array_map('strval', $invoiceNos));
        $stmt = $this->db->prepare("SELECT DISTINCT invoice_no, source_file FROM job_asn_records WHERE job_id = :job_id AND source_file != :sf");
        $stmt->execute([':job_id' => $jobId, ':sf' => $sourceFile]);
        $overlap = [];
        while ($row = $stmt->fetch()) {
            if (isset($wanted[$row['invoice_no']])) {
                $overlap[$row['invoice_no']][] = $row['source_file'];
            }
        }
        return $overlap;
    }

    public function describeStoredAsnFile(int $jobId, string $sourceFile, array $incomingRows): array
    {
        $stmt = $this->db->prepare("SELECT invoice_no, ean_upc, quantity, dest_store_no, selling_price, total_cost_ex_vat, total_vat, delivery_no FROM job_asn_records WHERE job_id = :job_id AND source_file = :sf");
        $stmt->execute([':job_id' => $jobId, ':sf' => $sourceFile]);
        $stored = [];
        while ($row = $stmt->fetch()) {
            $stored[] = $this->asnRowSignature($row);
        }

        $incoming = [];
        foreach ($incomingRows as $r) {
            $incoming[] = $this->asnRowSignature($this->cleanAsnRow($r));
        }

        sort($stored);
        sort($incoming);
        $job = $this->jobs->getJob($jobId);

        return [
            'stored' => count($stored),
            'incoming' => count($incoming),
            'identical' => !empty($stored) && $stored === $incoming,
            'reconciled' => !empty($job['asn_reconciled']),
        ];
    }

    private function cleanAsnRow(array $r): array
    {
        $number = function ($value) {
            $value = str_replace(',', '', (string)$value);
            return is_numeric($value) ? (float)$value : 0;
        };

        return [
            'invoice_no' => trim((string)($r['invoice_no'] ?? '')),
            'ean_upc' => trim((string)($r['ean_upc'] ?? '')),
            'quantity' => is_numeric($r['quantity'] ?? '') ? (int)$r['quantity'] : 0,
            'dest_store_no' => trim((string)($r['dest_store_no'] ?? '')),
            'selling_price' => $number($r['selling_price'] ?? ''),
            'total_cost_ex_vat' => $number($r['total_cost_ex_vat'] ?? ''),
            'total_vat' => $number($r['total_vat'] ?? ''),
            'delivery_no' => trim((string)($r['delivery_no'] ?? '')),
        ];
    }

    private function asnRowSignature(array $clean): string
    {
        return implode('|', [
            $clean['invoice_no'], $clean['ean_upc'], (int)$clean['quantity'], $clean['dest_store_no'],
            round((float)$clean['selling_price'], 4), round((float)$clean['total_cost_ex_vat'], 4),
            round((float)$clean['total_vat'], 4), $clean['delivery_no'],
        ]);
    }

    public function getAsnRecords(int $jobId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, job_id, COALESCE(source_file, '') AS source_file, invoice_no, dest_store_no,
                   ean_upc, quantity, selling_price, total_cost_ex_vat, total_vat, delivery_no
            FROM job_asn_records
            WHERE job_id = :job_id
            ORDER BY id ASC
        ");
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll();
    }

    /**
     * The source labels of the Job's ASN rows ("{file name} [{sheet}]"), in import order
     *
     * @return string[]
     */
    public function sourceFiles(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT source_file FROM job_asn_records WHERE job_id = :job_id AND source_file IS NOT NULL AND source_file != '' GROUP BY source_file ORDER BY MIN(id) ASC");
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function refreshAsnMetadata(int $jobId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT source_file
            FROM job_asn_records
            WHERE job_id = :job_id AND source_file IS NOT NULL AND source_file != ''
            GROUP BY source_file
            ORDER BY MIN(id) ASC
        ");
        $stmt->execute([':job_id' => $jobId]);
        $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM job_asn_records WHERE job_id = :job_id");
        $countStmt->execute([':job_id' => $jobId]);
        $totalRecords = (int)$countStmt->fetchColumn();

        if ($totalRecords === 0) {
            $summaryName = null;
        } elseif (empty($files)) {
            $summaryName = 'ASN_Upload.xlsx';
        } elseif (count($files) === 1) {
            $summaryName = $files[0];
        } else {
            $summaryName = count($files) . ' ไฟล์: ' . implode(', ', $files);
        }

        $this->jobs->setAsnFilename($jobId, $summaryName, $totalRecords === 0);

        return $summaryName;
    }

    public function deleteAsnRecords(int $jobId, array $asnIds): int
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $asnIds), function ($v) {
            return $v > 0;
        })));
        if (empty($cleanIds)) {
            return 0;
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $deletedCount = 0;
            foreach (array_chunk($cleanIds, 450) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sql = "DELETE FROM job_asn_records WHERE job_id = ? AND id IN ({$placeholders})";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array_merge([$jobId], $chunk));
                $deletedCount += $stmt->rowCount();
            }

            $this->refreshAsnMetadata($jobId);
            $this->jobs->resetAsnReconciled($jobId);

            if ($ownTx) {
                $this->db->commit();
            }

            return $deletedCount;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteJobAsnRecords(int $jobId): void
    {
        $stmt = $this->db->prepare("DELETE FROM job_asn_records WHERE job_id = :id");
        $stmt->execute([':id' => $jobId]);
    }

    /**
     * @param bool $append If true, appends new ASN file (replacing only rows with the same source_file)
     * @param string $duplicateMode For invoices another ASN file already holds: 'skip' them or 'replace' the earlier rows
     * @param string $sameFileMode When this file label was imported before: 'skip' leaves everything untouched, 'replace' reloads its rows
     */
    public function saveAsnRecords(int $jobId, string $asnFilename, array $rows, bool $append = true, string $duplicateMode = 'skip', string $sameFileMode = 'replace'): int
    {
        $this->lastAsnImport = self::NO_IMPORT;

        if ($append && $sameFileMode === 'skip' && $this->describeStoredAsnFile($jobId, $asnFilename, [])['stored'] > 0) {
            $this->lastAsnImport['skipped_same_file'] = true;
            return 0;
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            if ($append) {
                $del = $this->db->prepare("DELETE FROM job_asn_records WHERE job_id = :job_id AND source_file = :sf");
                $del->execute([':job_id' => $jobId, ':sf' => $asnFilename]);
                $this->lastAsnImport['replaced_same_file'] = $del->rowCount() > 0;

                $overlap = $this->findAsnOverlap($jobId, $asnFilename, array_map(function ($r) {
                    return trim((string)($r['invoice_no'] ?? ''));
                }, $rows));
                if (!empty($overlap)) {
                    if ($duplicateMode === 'replace') {
                        $delOld = $this->db->prepare("DELETE FROM job_asn_records WHERE job_id = :job_id AND invoice_no = :inv AND source_file != :sf");
                        foreach (array_keys($overlap) as $inv) {
                            $delOld->execute([':job_id' => $jobId, ':inv' => (string)$inv, ':sf' => $asnFilename]);
                        }
                        $this->lastAsnImport['replaced_invoices'] = count($overlap);
                    } else {
                        $rows = array_values(array_filter($rows, function ($r) use ($overlap) {
                            return !isset($overlap[trim((string)($r['invoice_no'] ?? ''))]);
                        }));
                        $this->lastAsnImport['skipped_invoices'] = count($overlap);
                    }
                }
            } else {
                $del = $this->db->prepare("DELETE FROM job_asn_records WHERE job_id = :job_id");
                $del->execute([':job_id' => $jobId]);
            }

            $sql = "
                INSERT INTO job_asn_records (
                    job_id, source_file, invoice_no, invoice_date, gp_date, exp_delivery_date, ship_via,
                    trailer_nbr, dest_store_no, ean_upc, quantity, selling_price, total_cost_ex_vat,
                    total_vat, delivery_no, raw_json
                ) VALUES (
                    :job_id, :source_file, :invoice_no, :invoice_date, :gp_date, :exp_delivery_date, :ship_via,
                    :trailer_nbr, :dest_store_no, :ean_upc, :quantity, :selling_price, :total_cost_ex_vat,
                    :total_vat, :delivery_no, :raw_json
                )
            ";
            $stmt = $this->db->prepare($sql);

            $insertedCount = 0;
            foreach ($rows as $r) {
                $clean = $this->cleanAsnRow($r);

                $stmt->execute([
                    ':job_id' => $jobId,
                    ':source_file' => $asnFilename,
                    ':invoice_no' => $clean['invoice_no'],
                    ':invoice_date' => (string)($r['invoice_date'] ?? ''),
                    ':gp_date' => (string)($r['gp_date'] ?? ''),
                    ':exp_delivery_date' => (string)($r['exp_delivery_date'] ?? ''),
                    ':ship_via' => (string)($r['ship_via'] ?? ''),
                    ':trailer_nbr' => (string)($r['trailer_nbr'] ?? ''),
                    ':dest_store_no' => $clean['dest_store_no'],
                    ':ean_upc' => $clean['ean_upc'],
                    ':quantity' => $clean['quantity'],
                    ':selling_price' => $clean['selling_price'],
                    ':total_cost_ex_vat' => $clean['total_cost_ex_vat'],
                    ':total_vat' => $clean['total_vat'],
                    ':delivery_no' => $clean['delivery_no'],
                    ':raw_json' => json_encode($r, JSON_UNESCAPED_UNICODE)
                ]);
                $insertedCount++;
            }

            $this->refreshAsnMetadata($jobId);
            $this->jobs->resetAsnReconciled($jobId);

            if ($ownTx) {
                $this->db->commit();
            }

            return $insertedCount;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
