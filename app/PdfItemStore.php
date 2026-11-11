<?php

namespace App;

use PDO;

/**
 * The PDF data of a Job: extracted files (job_files) and their rows (job_items), plus the fix records
 * (job_invoice_fixes, job_invoice_fix_steps), which go when the PDF rows of their invoice go.
 */
class PdfItemStore
{
    /** @var PDO */
    private $db;

    /** @var JobFolders */
    private $folders;

    /** @var JobRepository */
    private $jobs;

    public function __construct(PDO $db, JobFolders $folders, JobRepository $jobs)
    {
        $this->db = $db;
        $this->folders = $folders;
        $this->jobs = $jobs;
    }

    public static function invoiceRoot(string $invoice): string
    {
        $parts = explode('-', $invoice);
        return preg_replace('/[^0-9]/', '', $parts[0]);
    }

    public function scanSourceFiles(int $jobId): array
    {
        $job = $this->jobs->getJob($jobId);
        if (!$job) {
            return [
                'total_in_myfile' => 0,
                'new_files' => [],
                'existing_files' => [],
                'duplicate_files' => [],
                'new_count' => 0,
                'existing_count' => 0,
                'duplicate_count' => 0,
                'upload_dir' => '',
                'finished_dir' => ''
            ];
        }

        $uploadDir = $this->folders->getJobUploadDir($job);
        $diskFiles = FileManager::getPdfFiles($uploadDir);

        $stmt = $this->db->prepare("SELECT filename FROM job_files WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        $existingMap = [];
        while ($row = $stmt->fetch()) {
            $existingMap[$row['filename']] = true;
        }

        $newFiles = [];
        $existingFiles = [];
        $duplicateFiles = [];

        $prefixLen = strlen(rtrim($uploadDir, '/\\')) + 1;
        $knownRoots = $this->existingInvoiceRoots($jobId);
        $extractor = new PdfExtractor();

        foreach ($diskFiles as $file) {
            $base = basename($file);
            $rel = strlen($file) > $prefixLen ? substr($file, $prefixLen) : $base;
            $rel = str_replace('\\', '/', $rel);

            $fileInfo = [
                'filename' => $base,
                'rel_path' => $rel
            ];

            if (isset($existingMap[$base])) {
                $existingFiles[] = $fileInfo;
                continue;
            }

            $roots = $extractor->invoiceRoots($file);
            $duplicateOf = null;
            foreach ($roots as $root) {
                if (isset($knownRoots[$root]) && $knownRoots[$root] !== $base) {
                    $duplicateOf = $knownRoots[$root];
                    break;
                }
            }

            if ($duplicateOf !== null) {
                $fileInfo['duplicate_of'] = $duplicateOf;
                $fileInfo['invoices'] = array_slice($roots, 0, 3);
                $duplicateFiles[] = $fileInfo;
                continue;
            }

            foreach ($roots as $root) {
                $knownRoots[$root] = $base;
            }
            $newFiles[] = $fileInfo;
        }

        return [
            'total_in_myfile' => count($diskFiles),
            'new_files' => $newFiles,
            'existing_files' => $existingFiles,
            'duplicate_files' => $duplicateFiles,
            'new_count' => count($newFiles),
            'existing_count' => count($existingFiles),
            'duplicate_count' => count($duplicateFiles),
            'upload_dir' => JobFolders::displayPath($uploadDir),
            'finished_dir' => JobFolders::displayPath($this->folders->getJobFinishedDir($job, false))
        ];
    }

    /**
     * PDFs of upload/{Job}/invoice/pdf whose name the Job has not read yet (read files stay in that folder)
     */
    public function waitingCount(array $job): int
    {
        $stmt = $this->db->prepare("SELECT filename FROM job_files WHERE job_id = :job_id");
        $stmt->execute([':job_id' => (int)$job['id']]);
        $read = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        $count = 0;
        foreach (FileManager::getPdfFiles($this->folders->getJobUploadDir($job, false)) as $file) {
            $count += isset($read[basename($file)]) ? 0 : 1;
        }
        return $count;
    }

    /**
     * Map of invoice root => filename for every bill already extracted into the Job
     */
    public function existingInvoiceRoots(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT filename, tax_invoice FROM job_items WHERE job_id = :job_id GROUP BY filename, tax_invoice");
        $stmt->execute([':job_id' => $jobId]);
        $roots = [];
        while ($row = $stmt->fetch()) {
            $root = self::invoiceRoot((string)$row['tax_invoice']);
            if ($root !== '' && !isset($roots[$root])) {
                $roots[$root] = $row['filename'];
            }
        }
        return $roots;
    }

    /**
     * Forget an extracted file and its rows (the PDF itself is left where it is)
     */
    public function deleteFileRecords(int $jobId, string $filename): void
    {
        $this->forgetInvoiceFixes($jobId, 'filename = ?', [$filename]);
        $stmt = $this->db->prepare("DELETE FROM job_files WHERE job_id = :job_id AND filename = :filename");
        $stmt->execute([':job_id' => $jobId, ':filename' => $filename]);
    }

    public function getJobItems(int $jobId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM job_items
            WHERE job_id = :job_id
            ORDER BY id ASC
        ");
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll();
    }

    /**
     * Record an extracted PDF file and its rows; a file already recorded is left alone unless $overwrite.
     * Each row of $items gets its new id, job_id and file_id.
     */
    public function recordExtractedFile(int $jobId, string $filename, string $storedPath, array &$items, bool $overwrite = false, bool $deferStats = false): void
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare("SELECT id FROM job_files WHERE job_id = :job_id AND filename = :filename");
            $stmt->execute([':job_id' => $jobId, ':filename' => $filename]);
            $fileRow = $stmt->fetch();

            if ($fileRow) {
                if (!$overwrite) {
                    if ($ownTx) {
                        $this->db->commit();
                    }
                    return;
                }
                $fileId = (int)$fileRow['id'];
                $this->forgetInvoiceFixes($jobId, 'file_id = ?', [$fileId]);
                $delStmt = $this->db->prepare("DELETE FROM job_items WHERE job_id = :job_id AND file_id = :file_id");
                $delStmt->execute([':job_id' => $jobId, ':file_id' => $fileId]);
            } else {
                $insStmt = $this->db->prepare("
                    INSERT INTO job_files (job_id, filename, file_path, file_size, page_count, extracted_at)
                    VALUES (:job_id, :filename, :file_path, :file_size, :page_count, CURRENT_TIMESTAMP)
                ");
                $insStmt->execute([
                    ':job_id' => $jobId,
                    ':filename' => $filename,
                    ':file_path' => $storedPath,
                    ':file_size' => file_exists($storedPath) ? filesize($storedPath) : 0,
                    ':page_count' => !empty($items) ? max(array_column($items, 'page_no')) : 1
                ]);
                $fileId = (int)$this->db->lastInsertId();
            }

            if (!empty($items)) {
                $itemSql = "
                    INSERT INTO job_items (
                        job_id, file_id, filename, page_no, tax_invoice, date, address, tax_id, branch_code,
                        ship_to_party, term_a, term_b, product_sz, barcode, description, units, unit_price,
                        amount, total_shipped, no_cartons, promotion_discount, total_amount, discount_percent,
                        total_discount, amt_after_discount, amt_excl_vat, vat_amount, amt_incl_vat
                    ) VALUES (
                        :job_id, :file_id, :filename, :page_no, :tax_invoice, :date, :address, :tax_id, :branch_code,
                        :ship_to_party, :term_a, :term_b, :product_sz, :barcode, :description, :units, :unit_price,
                        :amount, :total_shipped, :no_cartons, :promotion_discount, :total_amount, :discount_percent,
                        :total_discount, :amt_after_discount, :amt_excl_vat, :vat_amount, :amt_incl_vat
                    )
                ";
                $itemStmt = $this->db->prepare($itemSql);

                foreach ($items as &$it) {
                    $cleanUnit = is_numeric($it['units']) ? (int)$it['units'] : 0;
                    $cleanUnitPrice = is_numeric(str_replace(',', '', $it['unit_price'] ?? '')) ? (float)str_replace(',', '', $it['unit_price']) : 0;
                    $cleanAmount = is_numeric(str_replace(',', '', $it['amount'] ?? '')) ? (float)str_replace(',', '', $it['amount']) : 0;

                    $itemStmt->execute([
                        ':job_id' => $jobId,
                        ':file_id' => $fileId,
                        ':filename' => $filename,
                        ':page_no' => $it['page_no'] ?? 1,
                        ':tax_invoice' => $it['tax_invoice'] ?? '',
                        ':date' => $it['date'] ?? '',
                        ':address' => $it['address'] ?? '',
                        ':tax_id' => $it['tax_id'] ?? '',
                        ':branch_code' => $it['branch_code'] ?? '',
                        ':ship_to_party' => $it['ship_to_party'] ?? '',
                        ':term_a' => $it['term_a'] ?? '',
                        ':term_b' => $it['term_b'] ?? '',
                        ':product_sz' => $it['product_sz'] ?? '',
                        ':barcode' => $it['barcode'] ?? '',
                        ':description' => $it['description'] ?? '',
                        ':units' => $cleanUnit,
                        ':unit_price' => $cleanUnitPrice,
                        ':amount' => $cleanAmount,
                        ':total_shipped' => $it['total_shipped'] ?? 'Null',
                        ':no_cartons' => $it['no_cartons'] ?? 'Null',
                        ':promotion_discount' => $it['promotion_discount'] ?? 'Null',
                        ':total_amount' => $it['total_amount'] ?? 'Null',
                        ':discount_percent' => $it['discount_percent'] ?? 'Null',
                        ':total_discount' => $it['total_discount'] ?? 'Null',
                        ':amt_after_discount' => $it['amt_after_discount'] ?? 'Null',
                        ':amt_excl_vat' => $it['amt_excl_vat'] ?? 'Null',
                        ':vat_amount' => $it['vat_amount'] ?? 'Null',
                        ':amt_incl_vat' => $it['amt_incl_vat'] ?? 'Null'
                    ]);
                    $it['id'] = (int)$this->db->lastInsertId();
                    $it['job_id'] = $jobId;
                    $it['file_id'] = $fileId;
                }
                unset($it);
            }

            if (!$deferStats) {
                $this->jobs->updateJobStats($jobId);
            }

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array $batchEntries Array of ['filename' => string, 'storedPath' => string, 'items' => array]
     * @param array $removeFilenames Earlier files to forget in the same transaction
     * @return array Flattened array of inserted item rows with SQLite id
     */
    public function recordExtractedBatch(int $jobId, array &$batchEntries, bool $overwrite = false, array $removeFilenames = []): array
    {
        if (empty($batchEntries)) {
            return [];
        }
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        $insertedRows = [];
        try {
            foreach ($removeFilenames as $oldName) {
                $this->deleteFileRecords($jobId, $oldName);
            }
            foreach ($batchEntries as &$entry) {
                $this->recordExtractedFile(
                    $jobId,
                    $entry['filename'],
                    $entry['storedPath'],
                    $entry['items'],
                    $overwrite,
                    true
                );
                foreach ($entry['items'] as $row) {
                    $insertedRows[] = $row;
                }
            }
            unset($entry);
            $this->jobs->updateJobStats($jobId);
            if ($ownTx) {
                $this->db->commit();
            }
            return $insertedRows;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete PDF rows by id, the files left without rows (their PDF in the Job's finished folder too), and the Job's
     * processed folder once no row is left. job_files.file_path is not used: it names the folder at extraction time.
     */
    public function deleteJobItems(int $jobId, array $itemIds): int
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), function ($v) {
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
                $this->forgetInvoiceFixes($jobId, "id IN ({$placeholders})", $chunk);
                $sql = "DELETE FROM job_items WHERE job_id = ? AND id IN ({$placeholders})";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array_merge([$jobId], $chunk));
                $deletedCount += $stmt->rowCount();
            }

            $orphanStmt = $this->db->prepare("
                SELECT id, filename
                FROM job_files f
                WHERE f.job_id = :job_id
                  AND NOT EXISTS (SELECT 1 FROM job_items i WHERE i.file_id = f.id)
            ");
            $orphanStmt->execute([':job_id' => $jobId]);
            $orphans = $orphanStmt->fetchAll();

            if (!empty($orphans)) {
                $job = $this->jobs->getJob($jobId);
                $finishedDir = $job ? $this->folders->getJobFinishedDir($job, false) : null;
                $orphanIds = [];
                foreach ($orphans as $orph) {
                    $orphanIds[] = (int)$orph['id'];
                    $pdf = $finishedDir . DIRECTORY_SEPARATOR . $orph['filename'];
                    if ($finishedDir !== null && $orph['filename'] !== '' && is_file($pdf)) {
                        @unlink($pdf);
                    }
                }
                foreach (array_chunk($orphanIds, 450) as $idChunk) {
                    $ph = implode(',', array_fill(0, count($idChunk), '?'));
                    $delFileStmt = $this->db->prepare("DELETE FROM job_files WHERE job_id = ? AND id IN ({$ph})");
                    $delFileStmt->execute(array_merge([$jobId], $idChunk));
                }
            }

            $this->jobs->updateJobStats($jobId);

            $remainStmt = $this->db->prepare("SELECT COUNT(*) FROM job_items WHERE job_id = :job_id");
            $remainStmt->execute([':job_id' => $jobId]);
            $remainingItems = (int)$remainStmt->fetchColumn();

            if ($remainingItems === 0) {
                $job = $this->jobs->getJob($jobId);
                if ($job) {
                    $this->folders->removeProcessedJobFolder($job);
                }
            }

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

    public function deleteJobPdfData(int $jobId): void
    {
        $stmt = $this->db->prepare("DELETE FROM job_invoice_fixes WHERE job_id = :id");
        $stmt->execute([':id' => $jobId]);

        $stmt = $this->db->prepare("DELETE FROM job_invoice_fix_steps WHERE job_id = :id");
        $stmt->execute([':id' => $jobId]);

        $stmt = $this->db->prepare("DELETE FROM job_items WHERE job_id = :id");
        $stmt->execute([':id' => $jobId]);

        $stmt = $this->db->prepare("DELETE FROM job_files WHERE job_id = :id");
        $stmt->execute([':id' => $jobId]);
    }

    /**
     * PDF files and rows as the PDF table lists them (files counted by the rows' file names)
     */
    public function itemCounts(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT NULLIF(filename, '')) AS file_count, COUNT(*) AS row_count FROM job_items WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        $pdf = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['files' => (int)$pdf['file_count'], 'rows' => (int)$pdf['row_count']];
    }

    public function storedCounts(int $jobId): array
    {
        $count = function (string $sql) use ($jobId): int {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':job_id' => $jobId]);
            return (int)$stmt->fetchColumn();
        };
        return [
            'files' => $count('SELECT COUNT(*) FROM job_files WHERE job_id = :job_id'),
            'rows' => $count('SELECT COUNT(*) FROM job_items WHERE job_id = :job_id'),
            'fixed_bills' => $count('SELECT COUNT(DISTINCT invoice_no) FROM job_invoice_fixes WHERE job_id = :job_id'),
        ];
    }

    public function fixedInvoiceNumbers(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT invoice_no FROM job_invoice_fixes WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Forget the fix records and fix steps of the invoices found in the PDF rows matching a filter (call before the rows go)
     *
     * @param string $itemFilter SQL on job_items with positional placeholders
     */
    private function forgetInvoiceFixes(int $jobId, string $itemFilter, array $params): void
    {
        foreach (['job_invoice_fixes', 'job_invoice_fix_steps'] as $table) {
            $stmt = $this->db->prepare("DELETE FROM {$table} WHERE job_id = ? AND invoice_no IN (SELECT tax_invoice FROM job_items WHERE job_id = ? AND {$itemFilter})");
            $stmt->execute(array_merge([$jobId, $jobId], $params));
        }
    }
}
