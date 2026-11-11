<?php

namespace App;

use PDO;

/**
 * The VBRP data of a Job: the Excel files read from its VBRP folders by the old page vbrp.php, or uploaded in tab 2 of
 * workspace.php (importUpload, no folder), in job_vbrp_files, and one row per data row of each sheet (job_vbrp_rows).
 * Only the columns of COLUMNS and HIDDEN_COLUMNS are kept, from DATA_START_ROW down. A file name is held
 * once per Job; reading it again replaces its rows. The Tax Invoice and Credit Note tables and folders are never
 * touched. The tables are created here (not in Database::initSchema) so a database made before this page works at once.
 */
class VbrpStore
{
    public const DATA_START_ROW = 4;

    /** Excel column letter => the field it fills, in the order the page shows them */
    public const COLUMNS = [
        'A' => 'combine',
        'C' => 'invoice',
        'E' => 'delivery_no',
        'H' => 'quantity',
        'CI' => 'selling_price',
        'CK' => 'total_cost_ex_vat',
        'CQ' => 'ean_upc',
        'EM' => 'total_vat',
    ];

    /** Read and kept but not shown by vbrp.php: Subtotal 3 decides whether a row counts (VbrpFigures::SKIP_FROM) */
    public const HIDDEN_COLUMNS = ['CJ' => 'subtotal_3'];

    /** The row 3 label of every column the Tax Invoice import reads (importUpload), compared trimmed and without case */
    public const LABELS = [
        'A' => 'Combine',
        'C' => 'Bill',
        'E' => 'Bill. Doc.',
        'H' => 'Billed Quantity',
        'CI' => 'Subtotal 2',
        'CJ' => 'Subtotal 3',
        'CK' => 'Subtotal 4',
        'CQ' => 'EAN/UPC',
        'EM' => 'Tax Amount',
    ];

    public const LABEL_ROW = 3;

    public const EXTENSIONS = ['xlsx'];

    private const TEXT_FIELDS = ['combine', 'invoice', 'delivery_no', 'ean_upc'];
    private const NUMBER_FIELDS = ['quantity', 'selling_price', 'total_cost_ex_vat', 'total_vat', 'subtotal_3'];

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
        self::createTables($db);
    }

    public static function createTables(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS job_vbrp_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                sheet_count INTEGER DEFAULT 0,
                extracted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(job_id, filename),
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS job_vbrp_rows (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                file_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                sheet TEXT NOT NULL,
                row_no INTEGER NOT NULL,
                combine TEXT,
                invoice TEXT,
                delivery_no TEXT,
                quantity REAL DEFAULT NULL,
                selling_price REAL DEFAULT NULL,
                total_cost_ex_vat REAL DEFAULT NULL,
                ean_upc TEXT,
                total_vat REAL DEFAULT NULL,
                subtotal_3 REAL DEFAULT NULL,
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                FOREIGN KEY (file_id) REFERENCES job_vbrp_files(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_vbrp_rows_job_invoice ON job_vbrp_rows(job_id, invoice);
        ");
        // Rows stored before Subtotal 3 was kept have it NULL, and such a row counts. Two requests may add it at once.
        if (!self::hasSubtotal3($db)) {
            try {
                $db->exec('ALTER TABLE job_vbrp_rows ADD COLUMN subtotal_3 REAL DEFAULT NULL');
            } catch (\PDOException $e) {
                if (!self::hasSubtotal3($db)) {
                    throw $e;
                }
            }
        }
    }

    private static function hasSubtotal3(PDO $db): bool
    {
        return in_array('subtotal_3', array_column($db->query('PRAGMA table_info(job_vbrp_rows)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
    }

    /**
     * Sorts the Excel files of upload/{Job}/invoice/vbrp (subfolders included) into files whose name is
     * already recorded and new files
     */
    public function scan(int $jobId): array
    {
        $job = $this->requireJob($jobId);
        $uploadDir = $this->folders->getJobVbrpUploadDir($job);
        $finishedDir = $this->folders->getJobVbrpFinishedDir($job);
        $diskFiles = FileManager::getFilesWithExtension($uploadDir, self::EXTENSIONS);

        $recorded = array_flip($this->recordedFilenames($jobId));
        $prefixLen = strlen(rtrim($uploadDir, '/\\')) + 1;
        $newFiles = [];
        $existingFiles = [];
        foreach ($diskFiles as $file) {
            $base = basename($file);
            $info = ['filename' => $base, 'rel_path' => str_replace('\\', '/', substr($file, $prefixLen))];
            if (isset($recorded[$base])) {
                $existingFiles[] = $info;
            } else {
                $newFiles[] = $info;
            }
        }

        return [
            'new_files' => $newFiles,
            'existing_files' => $existingFiles,
            'new_count' => count($newFiles),
            'existing_count' => count($existingFiles),
            'total_in_upload' => count($diskFiles),
            'upload_dir' => JobFolders::displayPath($uploadDir),
            'finished_dir' => JobFolders::displayPath($finishedDir),
        ];
    }

    /**
     * Reads the given Excel files. A file stays where it is when it cannot be read, holds no data row, or its name is
     * already recorded (unless $replaceExisting: then its rows go). Every other file moves to "finished" and its
     * rows are recorded. Any existing rows in the Job whose Column C (invoice) matches an invoice of the new file
     * are deleted before the new rows are inserted.
     *
     * @param string[] $relPaths paths inside upload/{Job}/invoice/vbrp, or file names in "finished"
     */
    public function extractBatch(int $jobId, array $relPaths, bool $replaceExisting = true): array
    {
        $job = $this->requireJob($jobId);
        $uploadDir = $this->folders->getJobVbrpUploadDir($job);
        $finishedDir = $this->folders->getJobVbrpFinishedDir($job);
        $recorded = array_flip($this->recordedFilenames($jobId));
        $processed = 0;
        $rowCount = 0;
        $skippedExisting = [];
        $noData = [];
        $failed = [];
        $replaced = [];
        $replacedInvoices = [];
        $replacedRows = 0;

        foreach ($relPaths as $relPath) {
            $srcPath = $this->locate($relPath, $uploadDir, $finishedDir);
            if ($srcPath === null) {
                continue;
            }
            $baseName = basename($srcPath);
            if (isset($recorded[$baseName]) && !$replaceExisting) {
                $skippedExisting[] = $baseName;
                continue;
            }

            try {
                $sheets = self::readFile($srcPath);
            } catch (\Throwable $e) {
                $failed[] = ['filename' => $baseName, 'message' => 'อ่านไฟล์ Excel ไม่ได้ (' . $e->getMessage() . ')'];
                continue;
            }
            $total = 0;
            foreach ($sheets as $sheet) {
                $total += count($sheet['rows']);
            }
            if ($total === 0) {
                $noData[] = $baseName;
                continue;
            }

            $path = FileManager::copyInto($srcPath, $finishedDir);
            $recResult = $this->record($jobId, $baseName, $path, $sheets, $finishedDir);
            if (isset($recorded[$baseName])) {
                $replaced[] = $baseName;
            }
            foreach ($recResult['replaced_files'] as $rf) {
                $replaced[] = $rf;
            }
            foreach ($recResult['replaced_invoices'] as $ri) {
                $replacedInvoices[] = $ri;
            }
            $replacedRows += $recResult['replaced_rows'];
            $recorded[$baseName] = true;
            $processed++;
            $rowCount += $total;
        }


        return [
            'processed_files' => $processed,
            'rows_count' => $rowCount,
            'skipped_existing' => $skippedExisting,
            'no_data' => $noData,
            'failed' => $failed,
            'replaced_files' => array_values(array_unique($replaced)),
            'replaced_invoices' => array_values(array_unique($replacedInvoices)),
            'replaced_rows' => $replacedRows,
        ];
    }

    /**
     * The data rows of every sheet of an Excel file: [['name' => sheet, 'rows' => [[row_no, field => value ...]]]].
     * A sheet without a data row has an empty 'rows'.
     */
    public static function readFile(string $path): array
    {
        $sheets = [];
        foreach (XlsxReader::sheets($path) as $sheet) {
            $sheets[] = ['name' => $sheet['name'], 'rows' => self::sheetRows($path, $sheet['entry'])];
        }
        return $sheets;
    }

    private static function sheetRows(string $path, string $entry): array
    {
        $columns = self::COLUMNS + self::HIDDEN_COLUMNS;
        $rows = [];
        foreach (XlsxReader::rows($path, $entry, array_keys($columns), self::DATA_START_ROW) as $rowNo => $cells) {
            $row = ['row_no' => $rowNo];
            foreach ($columns as $letter => $field) {
                $row[$field] = self::fieldValue($field, $cells[$letter] ?? null);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Reads an uploaded VBRP workbook straight from its temporary file for the Tax Invoice compare: no VBRP folder is
     * made and the file is not kept (job_vbrp_files.file_path is ''). Only the sheets whose row 3 carries LABELS are
     * read. Rows of an invoice the file holds, and earlier rows of the same file name, are replaced (record()).
     *
     * @return array filename, rows, invoices, replaced_invoices, replaced_rows, ignored_sheets, vbrp_counts
     */
    public function importUpload(int $jobId, string $tmpPath, string $originalName, int $uploadError = UPLOAD_ERR_OK): array
    {
        $this->requireJob($jobId);
        $name = basename(str_replace('\\', '/', $originalName));
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadError($uploadError));
        }
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true) || strpos($name, '~$') === 0) {
            throw new \RuntimeException('รับเฉพาะไฟล์ Excel (.xlsx)');
        }

        $sheets = [];
        $ignored = [];
        $firstMismatch = null;
        foreach (XlsxReader::sheets($tmpPath) as $sheet) {
            $mismatch = self::labelMismatch($tmpPath, $sheet['entry']);
            if ($mismatch === null) {
                $sheets[] = ['name' => $sheet['name'], 'rows' => self::sheetRows($tmpPath, $sheet['entry'])];
            } else {
                $ignored[] = $sheet['name'];
                $firstMismatch = $firstMismatch ?? $mismatch;
            }
        }
        if (empty($sheets)) {
            throw new \RuntimeException(vsprintf('ไม่ใช่ไฟล์ VBRP (ช่อง %s%d ควรเป็น "%s" แต่เป็น "%s")', $firstMismatch ?? ['C', self::LABEL_ROW, self::LABELS['C'], '']));
        }
        $invoices = [];
        $rowCount = 0;
        foreach ($sheets as $sheet) {
            $rowCount += count($sheet['rows']);
            foreach ($sheet['rows'] as $row) {
                if (trim((string)$row['invoice']) !== '') {
                    $invoices[trim((string)$row['invoice'])] = true;
                }
            }
        }
        if ($rowCount === 0) {
            throw new \RuntimeException('ไฟล์นี้ไม่มีแถวข้อมูล VBRP ตั้งแต่แถวที่ ' . self::DATA_START_ROW);
        }

        $recorded = $this->record($jobId, $name, '', $sheets);
        return [
            'filename' => $name,
            'rows' => $rowCount,
            'invoices' => count($invoices),
            'replaced_invoices' => $recorded['replaced_invoices'],
            'replaced_rows' => $recorded['replaced_rows'],
            'ignored_sheets' => $ignored,
            'vbrp_counts' => $this->counts($jobId),
        ];
    }

    /**
     * Why PHP refused an upload for its size, or null. A request over post_max_size reaches the script with $_POST and
     * $_FILES empty (so it would read as no Job), a file over upload_max_filesize with UPLOAD_ERR_INI_SIZE.
     *
     * @param int $contentLength the request's CONTENT_LENGTH, about the size of the file
     * @param bool $nothingReceived $_POST and $_FILES are both empty
     */
    public static function sizeRefusal(int $contentLength, bool $nothingReceived, int $uploadError, string $postMax, string $uploadMax): ?string
    {
        if (!($nothingReceived && $contentLength > 0) && $uploadError !== UPLOAD_ERR_INI_SIZE && $uploadError !== UPLOAD_ERR_FORM_SIZE) {
            return null;
        }
        return sprintf(
            'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไฟล์ประมาณ %s MB แต่ php.ini ตั้ง upload_max_filesize = %s และ post_max_size = %s) ให้เพิ่มสองค่านี้ใน php.ini แล้วเปิดเว็บเซิร์ฟเวอร์ใหม่',
            number_format($contentLength / 1048576, 1),
            $uploadMax,
            $postMax
        );
    }

    /**
     * The first cell of row 3 whose label is not the one LABELS gives: [letter, row, wanted label, label found], or null
     */
    private static function labelMismatch(string $path, string $entry): ?array
    {
        $found = [];
        foreach (XlsxReader::rows($path, $entry, array_keys(self::LABELS), self::LABEL_ROW) as $rowNo => $cells) {
            $found = $rowNo === self::LABEL_ROW ? $cells : [];
            break;
        }
        foreach (['C' => self::LABELS['C']] + self::LABELS as $letter => $label) {
            $cell = trim((string)($found[$letter] ?? ''));
            if (strcasecmp($cell, $label) !== 0) {
                return [$letter, self::LABEL_ROW, $label, $cell];
            }
        }
        return null;
    }

    /**
     * Every row of the Job in the order it was recorded
     */
    public function rows(int $jobId): array
    {
        $this->requireJob($jobId);
        $fields = array_merge(self::TEXT_FIELDS, self::NUMBER_FIELDS);
        $stmt = $this->db->prepare('SELECT id, filename, sheet, row_no, ' . implode(', ', $fields) . ' FROM job_vbrp_rows WHERE job_id = :job_id ORDER BY id ASC');
        $stmt->execute([':job_id' => $jobId]);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out = ['id' => (int)$row['id'], 'filename' => (string)$row['filename'], 'sheet' => (string)$row['sheet'], 'row_no' => (int)$row['row_no']];
            foreach (self::TEXT_FIELDS as $field) {
                $out[$field] = $row[$field] === null ? null : (string)$row[$field];
            }
            foreach (self::NUMBER_FIELDS as $field) {
                $out[$field] = $row[$field] === null ? null : (float)$row[$field];
            }
            $rows[] = $out;
        }
        return $rows;
    }

    public function counts(int $jobId): array
    {
        $count = function (string $table) use ($jobId): int {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE job_id = :job_id");
            $stmt->execute([':job_id' => $jobId]);
            return (int)$stmt->fetchColumn();
        };
        $invoices = $this->db->prepare("SELECT COUNT(DISTINCT TRIM(invoice)) FROM job_vbrp_rows WHERE job_id = :job_id AND TRIM(COALESCE(invoice, '')) != ''");
        $invoices->execute([':job_id' => $jobId]);
        return ['files' => $count('job_vbrp_files'), 'rows' => $count('job_vbrp_rows'), 'invoices' => (int)$invoices->fetchColumn()];
    }

    /**
     * Every VBRP file of the Job currently in "finished", with its active row and invoice counts
     */
    public function files(int $jobId): array
    {
        $this->requireJob($jobId);
        $stmt = $this->db->prepare("
            SELECT f.id, f.filename, f.sheet_count, f.extracted_at,
                   COUNT(r.id) AS row_count,
                   COUNT(DISTINCT CASE WHEN r.invoice IS NOT NULL AND r.invoice != '' THEN r.invoice END) AS invoice_count
            FROM job_vbrp_files f
            LEFT JOIN job_vbrp_rows r ON r.file_id = f.id
            WHERE f.job_id = :job_id
            GROUP BY f.id
            ORDER BY f.id ASC
        ");
        $stmt->execute([':job_id' => $jobId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int)$row['id'],
                'filename' => (string)$row['filename'],
                'sheet_count' => (int)$row['sheet_count'],
                'row_count' => (int)$row['row_count'],
                'invoice_count' => (int)$row['invoice_count'],
                'extracted_at' => (string)$row['extracted_at'],
            ];
        }
        return $out;
    }

    /**
     * Forgets every VBRP file and row of the Job; the Excel files stay in "finished"
     */
    public function clear(int $jobId): array
    {
        $this->requireJob($jobId);
        $this->db->beginTransaction();
        try {
            $deleted = [];
            foreach (['rows' => 'job_vbrp_rows', 'files' => 'job_vbrp_files'] as $key => $table) {
                $stmt = $this->db->prepare("DELETE FROM {$table} WHERE job_id = :job_id");
                $stmt->execute([':job_id' => $jobId]);
                $deleted[$key] = $stmt->rowCount();
            }
            $this->db->commit();
            return $deleted;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Saves uploaded Excel files into upload/{Job}/invoice/vbrp (an upload of the same name replaces the file waiting
     * there). $files is $_FILES['files'] of a multiple file input.
     *
     * @return array ['saved' => file names, 'rejected' => [['filename', 'message']]]
     */
    public function saveUploads(int $jobId, array $files): array
    {
        $job = $this->requireJob($jobId);
        $uploadDir = $this->folders->getJobVbrpUploadDir($job);
        $saved = [];
        $rejected = [];

        $names = (array)($files['name'] ?? []);
        foreach ($names as $i => $name) {
            $name = basename(str_replace('\\', '/', (string)$name));
            $error = (int)((array)$files['error'])[$i];
            $tmp = (string)((array)$files['tmp_name'])[$i];
            if ($error !== UPLOAD_ERR_OK) {
                $rejected[] = ['filename' => $name, 'message' => self::uploadError($error)];
            } elseif (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true) || strpos($name, '~$') === 0) {
                $rejected[] = ['filename' => $name, 'message' => 'รับเฉพาะไฟล์ Excel (.xlsx)'];
            } elseif (!is_uploaded_file($tmp) || !@move_uploaded_file($tmp, $uploadDir . DIRECTORY_SEPARATOR . $name)) {
                $rejected[] = ['filename' => $name, 'message' => 'บันทึกไฟล์ลงโฟลเดอร์ invoice/vbrp ไม่ได้'];
            } else {
                $saved[] = $name;
            }
        }
        return ['saved' => $saved, 'rejected' => $rejected];
    }

    /**
     * Records the file and its rows, first deleting any existing rows in the Job whose Column C (invoice) matches
     * an invoice in $sheets (as well as any earlier rows of the same filename). If an earlier file in the Job loses
     * all of its rows as a result, its job_vbrp_files entry (and its superseded file in $finishedDir) is removed.
     *
     * @param array $sheets readFile() answer
     * @return array ['replaced_invoices' => string[], 'replaced_rows' => int, 'replaced_files' => string[]]
     */
    private function record(int $jobId, string $filename, string $path, array $sheets, string $finishedDir = ''): array
    {
        $fields = array_merge(self::TEXT_FIELDS, self::NUMBER_FIELDS);
        $invoices = [];
        foreach ($sheets as $sheet) {
            foreach ($sheet['rows'] as $row) {
                $inv = trim((string)($row['invoice'] ?? ''));
                if ($inv !== '') {
                    $invoices[$inv] = true;
                }
            }
        }
        $invoiceList = array_keys($invoices);

        $replacedInvoices = [];
        $replacedRows = 0;
        $emptiedFiles = [];

        $this->db->beginTransaction();
        try {
            $sameFileStmt = $this->db->prepare('SELECT COUNT(*) FROM job_vbrp_files WHERE job_id = :job_id AND filename = :filename');
            $sameFileStmt->execute([':job_id' => $jobId, ':filename' => $filename]);
            $hadSameFile = (int)$sameFileStmt->fetchColumn() > 0;

            if (!empty($invoiceList)) {
                $delInvStmt = $this->db->prepare('DELETE FROM job_vbrp_rows WHERE job_id = :job_id AND invoice = :invoice');
                foreach ($invoiceList as $inv) {
                    $delInvStmt->execute([':job_id' => $jobId, ':invoice' => $inv]);
                    $deleted = $delInvStmt->rowCount();
                    if ($deleted > 0) {
                        $replacedInvoices[] = $inv;
                        $replacedRows += $deleted;
                    }
                }
            }

            foreach (['job_vbrp_rows', 'job_vbrp_files'] as $table) {
                $stmt = $this->db->prepare("DELETE FROM {$table} WHERE job_id = :job_id AND filename = :filename");
                $stmt->execute([':job_id' => $jobId, ':filename' => $filename]);
            }

            $this->db->prepare('INSERT INTO job_vbrp_files (job_id, filename, file_path, file_size, sheet_count, extracted_at) VALUES (:job_id, :filename, :file_path, :file_size, :sheet_count, CURRENT_TIMESTAMP)')
                ->execute([
                    ':job_id' => $jobId,
                    ':filename' => $filename,
                    ':file_path' => $path,
                    ':file_size' => is_file($path) ? filesize($path) : 0,
                    ':sheet_count' => count(array_filter($sheets, function ($sheet) {
                        return count($sheet['rows']) > 0;
                    })),
                ]);
            $fileId = (int)$this->db->lastInsertId();

            $rowStmt = $this->db->prepare(
                'INSERT INTO job_vbrp_rows (job_id, file_id, filename, sheet, row_no, ' . implode(', ', $fields) . ') VALUES (:job_id, :file_id, :filename, :sheet, :row_no, :' . implode(', :', $fields) . ')'
            );
            foreach ($sheets as $sheet) {
                foreach ($sheet['rows'] as $row) {
                    $params = [':job_id' => $jobId, ':file_id' => $fileId, ':filename' => $filename, ':sheet' => $sheet['name'], ':row_no' => $row['row_no']];
                    foreach ($fields as $field) {
                        $params[':' . $field] = $row[$field];
                    }
                    $rowStmt->execute($params);
                }
            }

            $emptyStmt = $this->db->prepare('
                SELECT filename FROM job_vbrp_files
                WHERE job_id = :job_id
                  AND NOT EXISTS (SELECT 1 FROM job_vbrp_rows WHERE job_vbrp_rows.file_id = job_vbrp_files.id)
            ');
            $emptyStmt->execute([':job_id' => $jobId]);
            $emptiedFiles = $emptyStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($emptiedFiles)) {
                $this->db->prepare('
                    DELETE FROM job_vbrp_files
                    WHERE job_id = :job_id
                      AND NOT EXISTS (SELECT 1 FROM job_vbrp_rows WHERE job_vbrp_rows.file_id = job_vbrp_files.id)
                ')->execute([':job_id' => $jobId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($finishedDir !== '' && !empty($emptiedFiles)) {
            foreach ($emptiedFiles as $emptyName) {
                if ($emptyName !== $filename) {
                    $oldPath = $finishedDir . DIRECTORY_SEPARATOR . basename($emptyName);
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            }
        }

        return [
            'replaced_invoices' => $replacedInvoices,
            'replaced_rows' => $replacedRows,
            'replaced_files' => array_values(array_unique(array_merge($hadSameFile ? [$filename] : [], $emptiedFiles))),
        ];
    }

    /**
     * A cell as the field holds it: text as written (a number Excel saved as 5.4E+12 becomes its digits), amounts
     * as float, null when empty or not a number
     *
     * @return string|float|null
     */
    private static function fieldValue(string $field, ?string $cell)
    {
        if ($cell === null) {
            return null;
        }
        $isNumeric = is_numeric($cell);
        if (in_array($field, self::NUMBER_FIELDS, true)) {
            return $isNumeric ? (float)$cell : null;
        }
        if ($isNumeric && preg_match('/[eE]/', $cell)) {
            return sprintf('%.0f', (float)$cell);
        }
        return $cell;
    }

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
     * Excel files of upload/{Job}/invoice/vbrp whose name the Job has not read yet (read files stay in that folder)
     */
    public function waitingCount(int $jobId): int
    {
        $read = array_flip($this->recordedFilenames($jobId));
        $count = 0;
        foreach (FileManager::getFilesWithExtension($this->folders->getJobVbrpUploadDir($this->requireJob($jobId), false), self::EXTENSIONS) as $file) {
            $count += isset($read[basename($file)]) ? 0 : 1;
        }
        return $count;
    }

    private function recordedFilenames(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT filename FROM job_vbrp_files WHERE job_id = :job_id');
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function uploadError(int $error): string
    {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (upload_max_filesize ใน php.ini)';
        }
        return 'อัปโหลดไฟล์ไม่สำเร็จ (รหัส ' . $error . ')';
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
