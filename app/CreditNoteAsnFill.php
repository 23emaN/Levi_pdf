<?php

namespace App;

use PDO;

/**
 * "ใส่ยอด PDF ลง ASN" (tab 4 of credit_note.php). The user uploads an ASN workbook and picks its sheets; the workbook
 * is kept as uploaded in CREDIT NOTE/ASN, each picked sheet keeps its header row and headers, and each ASN line keeps
 * every column of its row (job_cn_fill_*). fillFromPdf() puts on each line the Total Amt Excluding VAT and the VAT (7%)
 * of the credit note PDF with the same number (CreditNoteReconciler::key) or marks it MISSING; the page then patches
 * the kept workbook with these figures (assets/js/credit-note/asn-fill.js). Nothing here touches the compare rows of
 * tab 2 (job_cn_asn_records).
 */
class CreditNoteAsnFill
{
    public const FOUND = 'FOUND';
    public const MISSING = 'MISSING';

    /** The note of a line whose number a PDF has, but read before the right-hand totals were read (schema 9) */
    public const NO_AMOUNT_NOTE = 'PDF ไม่มียอด Total Amt Excluding VAT (ดึงข้อมูลใบลดหนี้ใหม่)';
    public const MISSING_NOTE = 'ไม่พบใน PDF';

    /** @var PDO */
    private $db;

    /** @var JobFolders */
    private $folders;

    /** @var JobRepository */
    private $jobs;

    /** @var CreditNoteStore */
    private $creditNotes;

    public function __construct(PDO $db, JobFolders $folders, JobRepository $jobs, CreditNoteStore $creditNotes)
    {
        $this->db = $db;
        $this->folders = $folders;
        $this->jobs = $jobs;
        $this->creditNotes = $creditNotes;
    }

    /**
     * Keeps one uploaded workbook and the sheets picked from it. A file name the Job already holds is replaced as a
     * whole: its earlier sheets, lines and figures go, so the kept workbook and its lines always belong together.
     *
     * @param array $sheets [{name, header_row (1-based), last_col (0-based), headers: [], rows: [{row_no, doc_no, barcode, values: []}]}]
     * @return array file_id, file_name, sheets, rows, replaced
     */
    public function import(int $jobId, string $tmpPath, string $originalName, int $uploadError, array $sheets): array
    {
        $job = $this->requireJob($jobId);
        $name = basename(str_replace('\\', '/', $originalName));
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ: ' . self::uploadError($uploadError));
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx' || strpos($name, '~$') === 0) {
            throw new \InvalidArgumentException('รับเฉพาะไฟล์ Excel (.xlsx)');
        }
        $clean = self::cleanSheets($sheets);
        if (!$clean) {
            throw new \InvalidArgumentException('ไม่ได้เลือกชีตที่มีแถว ASN (ต้องมีคอลัมน์เลขใบลดหนี้และบาร์โค้ด)');
        }

        $dir = $this->folders->getJobCnFillDir($job);
        $storedName = self::storedName($name);
        $target = $dir . DIRECTORY_SEPARATOR . $storedName;
        $partial = $target . '.part';
        $backup = $target . '.old';
        $moved = is_uploaded_file($tmpPath) ? @move_uploaded_file($tmpPath, $partial) : @copy($tmpPath, $partial);
        if (!$moved) {
            throw new \RuntimeException('บันทึกไฟล์ลงโฟลเดอร์ CREDIT NOTE/ASN ไม่ได้');
        }

        $this->db->beginTransaction();
        try {
            $old = $this->fileByName($jobId, $name);
            if ($old) {
                $this->db->prepare('DELETE FROM job_cn_fill_files WHERE id = :id')->execute([':id' => $old['id']]);
            }
            $this->db->prepare('INSERT INTO job_cn_fill_files (job_id, file_name, stored_name, file_size) VALUES (:job, :name, :stored, :size)')
                ->execute([':job' => $jobId, ':name' => $name, ':stored' => $storedName, ':size' => (int)filesize($partial)]);
            $fileId = (int)$this->db->lastInsertId();

            $insertSheet = $this->db->prepare('INSERT INTO job_cn_fill_sheets (job_id, file_id, sheet_name, header_row, last_col, headers_json) VALUES (:job, :file, :name, :header, :last, :headers)');
            $insertRow = $this->db->prepare('INSERT INTO job_cn_fill_rows (job_id, sheet_id, row_no, doc_no, doc_key, barcode, values_json) VALUES (:job, :sheet, :row, :doc, :key, :barcode, :values)');
            $rowCount = 0;
            foreach ($clean as $sheet) {
                $insertSheet->execute([
                    ':job' => $jobId, ':file' => $fileId, ':name' => $sheet['name'], ':header' => $sheet['header_row'],
                    ':last' => $sheet['last_col'], ':headers' => json_encode($sheet['headers'], JSON_UNESCAPED_UNICODE),
                ]);
                $sheetId = (int)$this->db->lastInsertId();
                foreach ($sheet['rows'] as $row) {
                    $insertRow->execute([
                        ':job' => $jobId, ':sheet' => $sheetId, ':row' => $row['row_no'], ':doc' => $row['doc_no'],
                        ':key' => CreditNoteReconciler::key($row['doc_no']), ':barcode' => $row['barcode'],
                        ':values' => json_encode($row['values'], JSON_UNESCAPED_UNICODE),
                    ]);
                    $rowCount++;
                }
            }

            // The earlier workbook of this name steps aside until the new one is in place, so a failure leaves it
            if (is_file($target) && !@rename($target, $backup)) {
                throw new \RuntimeException("ไฟล์ {$storedName} ในโฟลเดอร์ CREDIT NOTE/ASN เปิดค้างอยู่ ปิดไฟล์แล้วนำเข้าใหม่");
            }
            if (!@rename($partial, $target)) {
                throw new \RuntimeException('บันทึกไฟล์ลงโฟลเดอร์ CREDIT NOTE/ASN ไม่ได้');
            }
            $this->db->commit();
            @unlink($backup);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            @unlink($partial);
            if (is_file($backup) && !is_file($target)) {
                @rename($backup, $target);
            }
            throw $e;
        }

        return ['file_id' => $fileId, 'file_name' => $name, 'sheets' => count($clean), 'rows' => $rowCount, 'replaced' => (bool)$old];
    }

    /**
     * Every kept workbook with its sheets, and every line, in import order
     */
    public function data(int $jobId): array
    {
        $this->requireJob($jobId);
        $files = [];
        $stmt = $this->db->prepare('SELECT id, file_name, file_size, imported_at, filled_at FROM job_cn_fill_files WHERE job_id = :job ORDER BY id ASC');
        $stmt->execute([':job' => $jobId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[(int)$row['id']] = [
                'id' => (int)$row['id'], 'file_name' => $row['file_name'], 'file_size' => (int)$row['file_size'],
                'imported_at' => $row['imported_at'], 'filled_at' => $row['filled_at'], 'sheets' => [],
            ];
        }

        $sheets = [];
        $stmt = $this->db->prepare('SELECT id, file_id, sheet_name, header_row, last_col, headers_json FROM job_cn_fill_sheets WHERE job_id = :job ORDER BY id ASC');
        $stmt->execute([':job' => $jobId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sheet = [
                'id' => (int)$row['id'], 'file_id' => (int)$row['file_id'], 'name' => $row['sheet_name'],
                'header_row' => (int)$row['header_row'], 'last_col' => (int)$row['last_col'],
                'headers' => json_decode($row['headers_json'], true) ?: [],
            ];
            $sheets[$sheet['id']] = $sheet;
            if (isset($files[$sheet['file_id']])) {
                $files[$sheet['file_id']]['sheets'][] = $sheet;
            }
        }

        $rows = [];
        $stmt = $this->db->prepare('SELECT id, sheet_id, row_no, doc_no, barcode, values_json, pdf_status, pdf_cost, pdf_vat, pdf_file FROM job_cn_fill_rows WHERE job_id = :job ORDER BY id ASC');
        $stmt->execute([':job' => $jobId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'id' => (int)$row['id'], 'sheet_id' => (int)$row['sheet_id'], 'row_no' => (int)$row['row_no'],
                'doc_no' => $row['doc_no'], 'barcode' => $row['barcode'],
                'values' => json_decode($row['values_json'], true) ?: [],
                'pdf_status' => $row['pdf_status'],
                'pdf_cost' => $row['pdf_cost'] === null ? null : (float)$row['pdf_cost'],
                'pdf_vat' => $row['pdf_vat'] === null ? null : (float)$row['pdf_vat'],
                'pdf_file' => $row['pdf_file'],
            ];
        }

        return ['files' => array_values($files), 'rows' => $rows, 'summary' => self::summarize($rows, $sheets, $files)];
    }

    /**
     * Reads the totals of the Job's credit note PDFs (tab 1) onto every line: FOUND with the Total Amt Excluding VAT
     * and VAT (7%) of the first document of that number that has them, otherwise MISSING. Every line is read again,
     * so a PDF added or cleared since is reflected.
     */
    public function fillFromPdf(int $jobId): array
    {
        $this->requireJob($jobId);
        $totals = [];
        $numbered = [];
        foreach ($this->creditNotes->docs($jobId) as $doc) {
            $key = CreditNoteReconciler::key((string)$doc['doc_no']);
            if ($key === '') {
                continue;
            }
            $numbered[$key] = true;
            if (!isset($totals[$key]) && $doc['excl_vat'] !== null) {
                $totals[$key] = ['cost' => (float)$doc['excl_vat'], 'vat' => $doc['vat'] === null ? 0.0 : (float)$doc['vat'], 'file' => (string)$doc['filename']];
            }
        }

        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT id, doc_key FROM job_cn_fill_rows WHERE job_id = :job');
            $select->execute([':job' => $jobId]);
            $found = $this->db->prepare('UPDATE job_cn_fill_rows SET pdf_status = :status, pdf_cost = :cost, pdf_vat = :vat, pdf_file = :file WHERE id = :id');
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pdf = $totals[$row['doc_key']] ?? null;
                $found->execute([
                    ':status' => $pdf ? self::FOUND : self::MISSING,
                    ':cost' => $pdf ? round($pdf['cost'], 2) : null,
                    ':vat' => $pdf ? round($pdf['vat'], 2) : null,
                    ':file' => $pdf ? $pdf['file'] : (isset($numbered[$row['doc_key']]) ? self::NO_AMOUNT_NOTE : null),
                    ':id' => (int)$row['id'],
                ]);
            }
            $this->db->prepare('UPDATE job_cn_fill_files SET filled_at = CURRENT_TIMESTAMP WHERE job_id = :job')->execute([':job' => $jobId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->data($jobId) + ['pdf_documents' => count($totals)];
    }

    /**
     * The kept workbook of one file of the Job, or null
     */
    public function filePath(int $jobId, int $fileId): ?array
    {
        $job = $this->requireJob($jobId);
        $stmt = $this->db->prepare('SELECT file_name, stored_name FROM job_cn_fill_files WHERE job_id = :job AND id = :id');
        $stmt->execute([':job' => $jobId, ':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $path = $this->folders->getJobCnFillDir($job, false) . DIRECTORY_SEPARATOR . $row['stored_name'];
        return is_file($path) ? ['path' => $path, 'file_name' => $row['file_name']] : null;
    }

    /**
     * Removes the files of $fileIds (every file when empty), their lines and their kept workbooks
     */
    public function clear(int $jobId, array $fileIds = []): array
    {
        $job = $this->requireJob($jobId);
        $stmt = $this->db->prepare('SELECT id, stored_name FROM job_cn_fill_files WHERE job_id = :job');
        $stmt->execute([':job' => $jobId]);
        $wanted = array_flip(array_map('intval', $fileIds));
        $dir = $this->folders->getJobCnFillDir($job, false);
        $files = 0;
        $rows = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $file) {
            if ($wanted && !isset($wanted[(int)$file['id']])) {
                continue;
            }
            $count = $this->db->prepare('SELECT COUNT(*) FROM job_cn_fill_rows WHERE sheet_id IN (SELECT id FROM job_cn_fill_sheets WHERE file_id = :id)');
            $count->execute([':id' => (int)$file['id']]);
            $rows += (int)$count->fetchColumn();
            $this->db->prepare('DELETE FROM job_cn_fill_files WHERE id = :id')->execute([':id' => (int)$file['id']]);
            @unlink($dir . DIRECTORY_SEPARATOR . $file['stored_name']);
            $files++;
        }
        return ['files' => $files, 'rows' => $rows];
    }

    /** The ASN columns the overview reads, found by header as the import of the compare finds them (asn-sheets.js) */
    private const OVERVIEW_COLUMNS = [
        'store_no' => '/dest.*store|store\s*no|branch/i',
        'quantity' => '/^quantity$|^qty$/i',
        'total_cost_ex_vat' => '/total\s*cost.*ex.*vat|cost.*ex.*vat|amt.*ex.*vat/i',
    ];

    /**
     * The overview (tab 3): one row per credit note number of the imported lines, in import order. MATCH when its
     * lines got the PDF totals, MISMATCH when it is not found in a PDF, PENDING when the PDF was not read for it yet
     * (a number found in one file and waiting in another is PENDING; not found anywhere wins over both). The ASN
     * amount is the first positive Total Cost Ex VAT of its lines, the quantity the sum of their Quantity.
     */
    public function overview(int $jobId): array
    {
        $data = $this->data($jobId);
        $columns = [];
        foreach ($data['files'] as $file) {
            foreach ($file['sheets'] as $sheet) {
                $columns[$sheet['id']] = self::overviewColumns($sheet['headers']);
            }
        }
        $pages = [];
        foreach ($this->creditNotes->docs($jobId) as $doc) {
            $key = CreditNoteReconciler::key((string)$doc['doc_no']);
            if ($key !== '' && !isset($pages[$key])) {
                $pages[$key] = $doc['pages'];
            }
        }

        $rank = [self::MISSING => 2, '' => 1, self::FOUND => 0];
        $docs = [];
        foreach ($data['rows'] as $row) {
            $key = CreditNoteReconciler::key($row['doc_no']);
            $cols = $columns[$row['sheet_id']] ?? [];
            $value = function (string $field) use ($cols, $row) {
                return isset($cols[$field]) ? ($row['values'][$cols[$field]] ?? null) : null;
            };
            if (!isset($docs[$key])) {
                $docs[$key] = [
                    'doc_no' => $row['doc_no'], 'store_no' => null, 'units' => null, 'amount' => null,
                    'pdf_cost' => null, 'pdf_vat' => null, 'source_files' => [], 'pages' => $pages[$key] ?? null,
                    'lines' => 0, '_status' => $row['pdf_status'], 'note' => null,
                ];
            }
            $doc = &$docs[$key];
            $doc['lines']++;
            $store = trim((string)$value('store_no'));
            if ($doc['store_no'] === null && $store !== '') {
                $doc['store_no'] = $store;
            }
            if (is_numeric($value('quantity'))) {
                $doc['units'] = ($doc['units'] ?? 0) + (float)$value('quantity');
            }
            $cost = $value('total_cost_ex_vat');
            if ($doc['amount'] === null && is_numeric($cost) && (float)$cost > 0) {
                $doc['amount'] = round((float)$cost, 2);
            }
            if ($row['pdf_status'] === self::FOUND && $doc['pdf_cost'] === null) {
                $doc['pdf_cost'] = $row['pdf_cost'];
                $doc['pdf_vat'] = $row['pdf_vat'];
                $doc['source_files'] = [$row['pdf_file']];
            }
            if ($row['pdf_status'] === self::MISSING && $doc['note'] === null) {
                $doc['note'] = $row['pdf_file'] ?: self::MISSING_NOTE;
            }
            if (($rank[$row['pdf_status']] ?? 1) > ($rank[$doc['_status']] ?? 1)) {
                $doc['_status'] = $row['pdf_status'];
            }
            unset($doc);
        }

        $status = [self::FOUND => 'MATCH', self::MISSING => 'MISMATCH', '' => 'PENDING'];
        $documents = [];
        $count = ['MATCH' => 0, 'MISMATCH' => 0, 'PENDING' => 0];
        foreach ($docs as $doc) {
            $doc['status'] = $status[$doc['_status']] ?? 'PENDING';
            $doc['problem'] = $doc['status'] === 'MISMATCH' ? $doc['note'] : '';
            unset($doc['_status'], $doc['note']);
            $count[$doc['status']]++;
            $documents[] = $doc;
        }
        return [
            'total' => count($documents),
            'complete' => $count['MATCH'],
            'error' => $count['MISMATCH'],
            'pending' => $count['PENDING'],
            'files' => count($data['files']),
            'documents' => $documents,
        ];
    }

    /** Column index by overview field, the first header that matches each, headers read with _ as a space */
    private static function overviewColumns(array $headers): array
    {
        $found = [];
        foreach ($headers as $i => $header) {
            $text = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', (string)$header)));
            foreach (self::OVERVIEW_COLUMNS as $field => $pattern) {
                if (!isset($found[$field]) && preg_match($pattern, $text)) {
                    $found[$field] = $i;
                    break;
                }
            }
        }
        return $found;
    }

    public function counts(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT (SELECT COUNT(*) FROM job_cn_fill_files WHERE job_id = :a) AS files, (SELECT COUNT(*) FROM job_cn_fill_rows WHERE job_id = :b) AS rows');
        $stmt->execute([':a' => $jobId, ':b' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['files' => (int)$row['files'], 'rows' => (int)$row['rows']];
    }

    /**
     * Counts of lines and of credit notes (distinct numbers) by state, and the credit notes not found in a PDF with
     * where their lines are
     */
    private static function summarize(array $rows, array $sheets, array $files): array
    {
        $lines = ['total' => count($rows), self::FOUND => 0, self::MISSING => 0, 'pending' => 0];
        $docs = ['total' => [], self::FOUND => [], self::MISSING => [], 'pending' => []];
        $missing = [];
        foreach ($rows as $row) {
            $state = $row['pdf_status'] === self::FOUND || $row['pdf_status'] === self::MISSING ? $row['pdf_status'] : 'pending';
            $key = CreditNoteReconciler::key($row['doc_no']);
            $lines[$state]++;
            $docs['total'][$key] = true;
            $docs[$state][$key] = true;
            if ($state !== self::MISSING) {
                continue;
            }
            $sheet = $sheets[$row['sheet_id']] ?? null;
            $where = $sheet ? ($files[$sheet['file_id']]['file_name'] ?? '') . ' / ' . $sheet['name'] : '';
            if (!isset($missing[$key])) {
                $missing[$key] = ['doc_no' => $row['doc_no'], 'rows' => 0, 'where' => [], 'note' => $row['pdf_file'] ?: self::MISSING_NOTE];
            }
            $missing[$key]['rows']++;
            $missing[$key]['where'][$where] = true;
        }
        foreach ($missing as &$doc) {
            $doc['where'] = array_keys($doc['where']);
        }
        unset($doc);
        return [
            'lines' => $lines,
            'documents' => array_map('count', $docs),
            'missing' => array_values($missing),
        ];
    }

    /**
     * The sheets of the request as they are stored; the request is the only place they come from, so this is where
     * they are checked
     */
    private static function cleanSheets(array $sheets): array
    {
        $clean = [];
        $names = [];
        foreach ($sheets as $sheet) {
            if (!is_array($sheet)) {
                continue;
            }
            $name = trim((string)($sheet['name'] ?? ''));
            $headerRow = (int)($sheet['header_row'] ?? 0);
            $lastCol = (int)($sheet['last_col'] ?? -1);
            if ($name === '' || isset($names[$name]) || $headerRow < 1 || $lastCol < 0 || $lastCol > 16380) {
                continue;
            }
            $rows = [];
            foreach ((array)($sheet['rows'] ?? []) as $row) {
                $rowNo = (int)($row['row_no'] ?? 0);
                $docNo = trim((string)($row['doc_no'] ?? ''));
                if ($rowNo <= $headerRow || $docNo === '' || CreditNoteReconciler::key($docNo) === '') {
                    continue;
                }
                $rows[] = [
                    'row_no' => $rowNo,
                    'doc_no' => $docNo,
                    'barcode' => trim((string)($row['barcode'] ?? '')),
                    'values' => array_map(function ($value) {
                        return is_scalar($value) || $value === null ? $value : (string)json_encode($value);
                    }, array_values((array)($row['values'] ?? []))),
                ];
            }
            if (!$rows) {
                continue;
            }
            $names[$name] = true;
            $clean[] = [
                'name' => $name,
                'header_row' => $headerRow,
                'last_col' => $lastCol,
                'headers' => array_map('strval', array_values((array)($sheet['headers'] ?? []))),
                'rows' => $rows,
            ];
        }
        return $clean;
    }

    /** The name the workbook is kept under: the upload's own name without characters Windows refuses */
    private static function storedName(string $name): string
    {
        $safe = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '_', $name);
        return $safe === '' || $safe === null ? 'ASN.xlsx' : $safe;
    }

    private function fileByName(int $jobId, string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT id FROM job_cn_fill_files WHERE job_id = :job AND file_name = :name');
        $stmt->execute([':job' => $jobId, ':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function requireJob(int $jobId): array
    {
        $job = $this->jobs->getJob($jobId);
        if (!$job) {
            throw new \RuntimeException('ไม่พบ Job นี้');
        }
        return $job;
    }

    private static function uploadError(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (upload_max_filesize ' . ini_get('upload_max_filesize') . ')';
            case UPLOAD_ERR_PARTIAL:
                return 'ไฟล์อัปโหลดมาไม่ครบ';
            case UPLOAD_ERR_NO_FILE:
                return 'ไม่ได้เลือกไฟล์';
            default:
                return 'รหัสข้อผิดพลาด ' . $code;
        }
    }
}
