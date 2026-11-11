<?php

namespace App;

use PDO;

/**
 * The credit notes of a Job: the PDF files read from its CREDIT NOTE folders (job_cn_files), one row per credit
 * note document in them (job_cn_docs) and the item lines of each document (job_cn_items). A credit note number (the
 * full number, 93250803-1) is held by one file name at a time. The Tax Invoice tables and folders are never touched.
 */
class CreditNoteStore
{
    private const TEXT_FIELDS = ['filename', 'doc_no', 'doc_date', 'customer', 'format_type', 'pages'];
    private const AMOUNT_FIELDS = ['orig_amount', 'corr_amount', 'diff_amount', 'vat_amount', 'amount'];

    /** What CreditNoteReader adds to a document, by type; all NULL on a document stored before schema 9 */
    private const READER_FIELDS = [
        'store_no' => 'text', 'doc_date_iso' => 'text', 'shipped_units' => 'int', 'promo_discount' => 'float',
        'total_amount' => 'float', 'total_discount' => 'float', 'after_discount' => 'float', 'excl_vat' => 'float',
        'vat' => 'float', 'incl_vat' => 'float', 'gp_percent' => 'float', 'read_ok' => 'bool', 'read_note' => 'text',
    ];
    private const ITEM_FIELDS = ['barcode', 'product_code', 'description', 'units', 'unit_price', 'amount'];

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

    /**
     * Sorts the PDFs of the credit note "upload here" folder (subfolders included) into files whose name is already
     * recorded, files holding a credit note number that another file name holds, and new files
     */
    public function scan(int $jobId): array
    {
        $job = $this->requireJob($jobId);
        $uploadDir = $this->folders->getJobCnUploadDir($job);
        $finishedDir = $this->folders->getJobCnFinishedDir($job);
        FileManager::cleanEmptySubdirectories($uploadDir);
        $diskFiles = FileManager::getPdfFiles($uploadDir);

        $recorded = array_flip($this->recordedFilenames($jobId));
        $owners = $this->docNumberOwners($jobId);
        $extractor = new PdfExtractor();
        $prefixLen = strlen(rtrim($uploadDir, '/\\')) + 1;
        $newFiles = [];
        $existingFiles = [];
        $duplicateFiles = [];

        foreach ($diskFiles as $file) {
            $base = basename($file);
            $fileInfo = ['filename' => $base, 'rel_path' => str_replace('\\', '/', substr($file, $prefixLen))];
            if (isset($recorded[$base])) {
                $existingFiles[] = $fileInfo;
                continue;
            }

            try {
                $docNos = self::docNumbers($extractor->extractCnDocuments($file));
            } catch (\Throwable $e) {
                $docNos = [];
            }
            $others = self::otherOwners($owners, $docNos, $base);
            if ($others) {
                $duplicateFiles[] = $fileInfo + ['duplicate_of' => $others[0], 'doc_nos' => array_slice($docNos, 0, 3)];
                continue;
            }
            foreach ($docNos as $no) {
                $owners[$no] = $base;
            }
            $newFiles[] = $fileInfo;
        }

        return [
            'new_files' => $newFiles,
            'existing_files' => $existingFiles,
            'duplicate_files' => $duplicateFiles,
            'new_count' => count($newFiles),
            'existing_count' => count($existingFiles),
            'duplicate_count' => count($duplicateFiles),
            'total_in_upload' => count($diskFiles),
            'upload_dir' => JobFolders::displayPath($uploadDir),
            'finished_dir' => JobFolders::displayPath($finishedDir),
        ];
    }

    /**
     * Reads the given files of the credit note folders. A file stays where it is when it holds no credit note, cannot
     * be read, or holds a credit note number another file name holds (unless $replaceDuplicates: then that file's
     * rows go). Every other file moves to "finished" and its documents replace the rows of its file name.
     *
     * @param string[] $relPaths paths inside the credit note "upload here" folder, or file names in "finished"
     */
    public function extractBatch(int $jobId, array $relPaths, bool $replaceDuplicates): array
    {
        $job = $this->requireJob($jobId);
        $uploadDir = $this->folders->getJobCnUploadDir($job);
        $finishedDir = $this->folders->getJobCnFinishedDir($job);
        $reader = new CreditNoteReader();
        $owners = $this->docNumberOwners($jobId);
        $entries = [];
        $replaced = [];
        $skippedDuplicates = [];
        $notCreditNote = [];
        $failed = [];

        foreach ($relPaths as $relPath) {
            $srcPath = $this->locate($relPath, $uploadDir, $finishedDir);
            if ($srcPath === null) {
                continue;
            }
            $baseName = basename($srcPath);
            try {
                $docs = $reader->read($srcPath);
            } catch (\Throwable $e) {
                $failed[] = ['filename' => $baseName, 'message' => 'อ่านไฟล์ PDF ไม่ได้ (' . $e->getMessage() . ')'];
                continue;
            }
            if (!$docs) {
                $notCreditNote[] = $baseName;
                continue;
            }

            $docNos = self::docNumbers($docs);
            $others = self::otherOwners($owners, $docNos, $baseName);
            if ($others && !$replaceDuplicates) {
                $skippedDuplicates[] = ['filename' => $baseName, 'duplicate_of' => $others[0]];
                continue;
            }
            foreach ($others as $other) {
                unset($entries[$other]);
                $replaced[$other] = true;
                $owners = array_filter($owners, function ($owner) use ($other) {
                    return $owner !== $other;
                });
            }
            foreach ($docNos as $no) {
                $owners[$no] = $baseName;
            }
            unset($replaced[$baseName]);
            $entries[$baseName] = ['path' => $this->moveToFinished($srcPath, $uploadDir, $finishedDir), 'docs' => $docs];
        }

        FileManager::cleanEmptySubdirectories($uploadDir);
        $inserted = $this->record($jobId, $entries, array_keys($replaced));

        return [
            'processed_files' => count($entries),
            'documents_count' => count($inserted),
            'skipped_duplicates' => $skippedDuplicates,
            'not_credit_note' => $notCreditNote,
            'failed' => $failed,
            'replaced_files' => array_map('strval', array_keys($replaced)),
            'data' => $inserted,
        ];
    }

    /**
     * Every credit note document of the Job in the order it was recorded
     */
    public function docs(int $jobId): array
    {
        $this->requireJob($jobId);
        $stmt = $this->db->prepare('SELECT id, ' . implode(', ', self::docColumns()) . ' FROM job_cn_docs WHERE job_id = :job_id ORDER BY id ASC');
        $stmt->execute([':job_id' => $jobId]);
        $docs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $docs[] = self::docRow($row);
        }
        return $docs;
    }

    /**
     * docs() with each document's item lines under 'items', in printed order: what the credit note compare judges
     */
    public function docsWithItems(int $jobId): array
    {
        $docs = [];
        foreach ($this->docs($jobId) as $doc) {
            $docs[$doc['id']] = $doc + ['items' => []];
        }
        $stmt = $this->db->prepare('SELECT doc_id, ' . implode(', ', self::ITEM_FIELDS) . ' FROM job_cn_items WHERE job_id = :job_id ORDER BY id ASC');
        $stmt->execute([':job_id' => $jobId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($docs[(int)$row['doc_id']])) {
                $docs[(int)$row['doc_id']]['items'][] = [
                    'barcode' => (string)$row['barcode'],
                    'product_code' => $row['product_code'],
                    'description' => $row['description'],
                    'units' => $row['units'] === null ? null : (int)$row['units'],
                    'unit_price' => $row['unit_price'] === null ? null : (float)$row['unit_price'],
                    'amount' => $row['amount'] === null ? null : (float)$row['amount'],
                ];
            }
        }
        return array_values($docs);
    }

    public function counts(int $jobId): array
    {
        $count = function (string $table) use ($jobId): int {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE job_id = :job_id");
            $stmt->execute([':job_id' => $jobId]);
            return (int)$stmt->fetchColumn();
        };
        return ['files' => $count('job_cn_files'), 'documents' => $count('job_cn_docs')];
    }

    /**
     * Forgets every credit note file and document of the Job; the PDFs stay in "finished"
     */
    public function clear(int $jobId): array
    {
        $this->requireJob($jobId);
        $this->db->beginTransaction();
        try {
            $deleted = [];
            foreach (['items' => 'job_cn_items', 'documents' => 'job_cn_docs', 'files' => 'job_cn_files'] as $key => $table) {
                $stmt = $this->db->prepare("DELETE FROM {$table} WHERE job_id = :job_id");
                $stmt->execute([':job_id' => $jobId]);
                $deleted[$key] = $stmt->rowCount();
            }
            $this->db->commit();
            return ['files' => $deleted['files'], 'documents' => $deleted['documents']];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array $entries file name => ['path' => where the PDF is now, 'docs' => extracted documents]
     * @param array $removeFilenames earlier files whose rows go in the same transaction
     * @return array the inserted documents, shaped as docs() returns them
     */
    private function record(int $jobId, array $entries, array $removeFilenames): array
    {
        $fileStmt = $this->db->prepare("
            INSERT INTO job_cn_files (job_id, filename, file_path, file_size, page_count, extracted_at)
            VALUES (:job_id, :filename, :file_path, :file_size, :page_count, CURRENT_TIMESTAMP)
        ");
        $docStmt = $this->db->prepare("
            INSERT INTO job_cn_docs (job_id, file_id, " . implode(', ', self::docColumns()) . ")
            VALUES (:job_id, :file_id, :" . implode(', :', self::docColumns()) . ")
        ");
        $itemStmt = $this->db->prepare("
            INSERT INTO job_cn_items (job_id, file_id, doc_id, filename, doc_no, " . implode(', ', self::ITEM_FIELDS) . ")
            VALUES (:job_id, :file_id, :doc_id, :filename, :doc_no, :" . implode(', :', self::ITEM_FIELDS) . ")
        ");

        $this->db->beginTransaction();
        try {
            foreach (array_merge($removeFilenames, array_keys($entries)) as $filename) {
                $this->forgetFile($jobId, (string)$filename);
            }

            $inserted = [];
            foreach ($entries as $filename => $entry) {
                $pageCount = 1;
                foreach ($entry['docs'] as $doc) {
                    $range = explode('-', (string)$doc['pages']);
                    $pageCount = max($pageCount, (int)end($range));
                }
                $fileStmt->execute([
                    ':job_id' => $jobId,
                    ':filename' => (string)$filename,
                    ':file_path' => $entry['path'],
                    ':file_size' => is_file($entry['path']) ? filesize($entry['path']) : 0,
                    ':page_count' => $pageCount,
                ]);
                $fileId = (int)$this->db->lastInsertId();

                foreach ($entry['docs'] as $doc) {
                    $row = ['filename' => (string)$filename];
                    foreach (self::TEXT_FIELDS as $field) {
                        if ($field !== 'filename') {
                            $row[$field] = (string)($doc[$field] ?? '');
                        }
                    }
                    foreach (self::AMOUNT_FIELDS as $field) {
                        $row[$field] = self::amount((string)($doc[$field] ?? ''));
                    }
                    foreach (array_keys(self::READER_FIELDS) as $field) {
                        $row[$field] = is_bool($doc[$field]) ? (int)$doc[$field] : $doc[$field];
                    }
                    $params = [':job_id' => $jobId, ':file_id' => $fileId];
                    foreach ($row as $field => $value) {
                        $params[':' . $field] = $value;
                    }
                    $docStmt->execute($params);
                    $docId = (int)$this->db->lastInsertId();
                    foreach ($doc['items'] as $item) {
                        $itemParams = [':job_id' => $jobId, ':file_id' => $fileId, ':doc_id' => $docId, ':filename' => (string)$filename, ':doc_no' => $row['doc_no']];
                        foreach (self::ITEM_FIELDS as $field) {
                            $itemParams[':' . $field] = $item[$field];
                        }
                        $itemStmt->execute($itemParams);
                    }
                    $inserted[] = self::docRow(['id' => $docId] + $row);
                }
            }

            $this->db->commit();
            return $inserted;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function forgetFile(int $jobId, string $filename): void
    {
        foreach (['job_cn_items', 'job_cn_docs', 'job_cn_files'] as $table) {
            $stmt = $this->db->prepare("DELETE FROM {$table} WHERE job_id = :job_id AND filename = :filename");
            $stmt->execute([':job_id' => $jobId, ':filename' => $filename]);
        }
    }

    /**
     * The file a request names, found the way the Tax Invoice flow finds it (the path inside "upload here", then the
     * name in "upload here", then the name in "finished"); null unless it lies inside one of those two folders
     */
    private function locate(string $relPath, string $uploadDir, string $finishedDir): ?string
    {
        $baseName = basename(str_replace('\\', '/', $relPath));
        $candidates = [
            $uploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath),
            $uploadDir . DIRECTORY_SEPARATOR . $baseName,
            $finishedDir . DIRECTORY_SEPARATOR . $baseName,
        ];
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $real = realpath($path);
            $inside = $real !== false
                && (FileManager::isSubpath($real, (string)realpath($uploadDir)) || FileManager::isSubpath($real, (string)realpath($finishedDir)));
            return $inside ? $path : null;
        }
        return null;
    }

    /**
     * Moves (not copies) a PDF into "finished" and removes the "upload here" subfolders it leaves empty
     *
     * @return string where the PDF is now
     */
    private function moveToFinished(string $srcPath, string $uploadDir, string $finishedDir): string
    {
        $destPath = $finishedDir . DIRECTORY_SEPARATOR . basename($srcPath);
        if (realpath($srcPath) === realpath($destPath)) {
            return $destPath;
        }
        if (file_exists($destPath)) {
            @unlink($destPath);
        }
        if (!@rename($srcPath, $destPath) && @copy($srcPath, $destPath)) {
            @unlink($srcPath);
        }
        FileManager::pruneEmptyParents($srcPath, $uploadDir);
        return file_exists($destPath) ? $destPath : $srcPath;
    }

    private function recordedFilenames(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT filename FROM job_cn_files WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Map of credit note number => the file name that holds it
     */
    private function docNumberOwners(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT doc_no, filename FROM job_cn_docs WHERE job_id = :job_id ORDER BY id ASC");
        $stmt->execute([':job_id' => $jobId]);
        $owners = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($owners[$row['doc_no']])) {
                $owners[$row['doc_no']] = $row['filename'];
            }
        }
        return $owners;
    }

    /**
     * The distinct credit note numbers of extracted documents; "-" (no number found) is no number
     */
    private static function docNumbers(array $docs): array
    {
        $numbers = [];
        foreach ($docs as $doc) {
            $no = trim((string)($doc['doc_no'] ?? ''));
            if ($no !== '' && $no !== '-') {
                $numbers[$no] = true;
            }
        }
        return array_map('strval', array_keys($numbers));
    }

    /**
     * File names other than $filename that hold one of the numbers, first holder first
     */
    private static function otherOwners(array $owners, array $docNos, string $filename): array
    {
        $others = [];
        foreach ($docNos as $no) {
            if (isset($owners[$no]) && $owners[$no] !== $filename) {
                $others[$owners[$no]] = true;
            }
        }
        return array_map('strval', array_keys($others));
    }

    /**
     * An amount as the extractor prints it ("1,234.00", "-" when missing) as a number, or null
     */
    private static function amount(string $printed): ?float
    {
        $plain = str_replace(',', '', trim($printed));
        return is_numeric($plain) ? (float)$plain : null;
    }

    private static function docColumns(): array
    {
        return array_merge(self::TEXT_FIELDS, self::AMOUNT_FIELDS, array_keys(self::READER_FIELDS));
    }

    /**
     * A stored document as job_cn_docs answers it; has_items tells a document CreditNoteReader read (read_ok set)
     * from one stored before schema 9, which has no item lines and no right-hand totals
     */
    private static function docRow(array $row): array
    {
        $doc = ['id' => (int)$row['id']];
        foreach (self::TEXT_FIELDS as $field) {
            $doc[$field] = (string)$row[$field];
        }
        foreach (self::AMOUNT_FIELDS as $field) {
            $doc[$field] = $row[$field] === null ? null : (float)$row[$field];
        }
        $cast = [
            'text' => 'strval', 'int' => 'intval', 'float' => 'floatval', 'bool' => 'boolval',
        ];
        foreach (self::READER_FIELDS as $field => $type) {
            $doc[$field] = $row[$field] === null ? null : $cast[$type]($row[$field]);
        }
        $doc['has_items'] = $row['read_ok'] !== null;
        return $doc;
    }

    private function requireJob(int $jobId): array
    {
        $job = $this->jobs->getJob($jobId);
        if (!$job) {
            throw new \RuntimeException('ไม่พบ Job นี้');
        }
        return $job;
    }
}
