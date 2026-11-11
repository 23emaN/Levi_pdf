<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

// The compare of a big Job (cover_preview, reconcile) needs ~160 MB; AppServ's php.ini gives 128M and its Apache often
// ignores the .htaccess that raises it (AllowOverride None), so the limit is raised here whatever php.ini says
(function () {
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit === '-1') {
        return;
    }
    $bytes = (int)$limit;
    switch (strtolower(substr($limit, -1))) {
        case 'g': $bytes *= 1024;
        // no break
        case 'm': $bytes *= 1024;
        // no break
        case 'k': $bytes *= 1024;
    }
    if ($bytes < 1024 * 1024 * 1024) {
        @ini_set('memory_limit', '1024M');
    }
})();

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\CoverSheet;
use App\FileManager;
use App\PdfExtractor;
use App\JobManager;
use App\PrintManager;
use App\VbrpStore;

// A request over post_max_size arrives with $_POST and $_FILES empty, after PHP wrote its own warning; while that warning
// still sits in the output buffer it goes, so the answer stays JSON (job_vbrp_import names the limit)
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

if (!headers_sent() && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false && PHP_SAPI !== 'cli') {
    @ini_set('zlib.output_compression_level', '1');
    @ob_start('ob_gzhandler');
}
header('Content-Type: application/json; charset=utf-8');

// Catch any fatal PHP errors and output as JSON for easier debugging
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo apiJsonError($error['message'] . ' in ' . basename($error['file']) . ' on line ' . $error['line']);
    }
});

/**
 * The JSON of an error answer that is never empty: a message in the Windows Thai codepage (cp874, what Windows and
 * some PHP 7 builds on AppServ write) is turned into UTF-8, other bytes that are not UTF-8 become U+FFFD. json_encode
 * of a message that is not UTF-8 gives false, and an empty answer is all the page would see.
 */
function apiJsonError(string $message): string
{
    if (!preg_match('//u', $message) && function_exists('iconv')) {
        $converted = @iconv('CP874', 'UTF-8//IGNORE', $message);
        if (is_string($converted) && $converted !== '') {
            $message = $converted;
        }
    }
    $json = json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '{"status":"error","message":"Unknown server error"}' : $json;
}

/**
 * Helper to get folder path (PHP 7.3+ compatible)
 */
function getFolderPath($folder)
{
    if ($folder === 'invoice') {
        return Config::getInvoicePath();
    }
    if ($folder === 'cn') {
        return Config::getCnPath();
    }
    return Config::getMyfilePath();
}

/**
 * Strip unused large columns (e.g. full address, ship_to_party) from reconcile HTTP payload
 */
function compactReconcilePayload(array $reconcileData): array
{
    if (empty($reconcileData['results']) || !is_array($reconcileData['results'])) {
        return $reconcileData;
    }
    foreach ($reconcileData['results'] as &$row) {
        if (!empty($row['pdf']) && is_array($row['pdf'])) {
            $p = $row['pdf'];
            $row['pdf'] = [
                'tax_invoice' => $p['tax_invoice'] ?? '',
                'barcode' => $p['barcode'] ?? '',
                'description' => $p['description'] ?? '',
                'store_code' => $p['store_code'] ?? '',
                'branch_code' => $p['branch_code'] ?? '',
                'units' => $p['units'] ?? 0,
                'unit_price' => $p['unit_price'] ?? '',
                'inv_amt_excl_vat' => $p['inv_amt_excl_vat'] ?? 0,
                'amt_excl_vat' => $p['amt_excl_vat'] ?? '',
                'amount' => $p['amount'] ?? 0,
                'inv_vat_amount' => $p['inv_vat_amount'] ?? 0,
                'vat_amount' => $p['vat_amount'] ?? ''
            ];
        }
        if (!empty($row['asn']) && is_array($row['asn'])) {
            $a = $row['asn'];
            $row['asn'] = [
                'invoice_no' => $a['invoice_no'] ?? '',
                'ean_upc' => $a['ean_upc'] ?? '',
                'dest_store_no' => $a['dest_store_no'] ?? '',
                'quantity' => $a['quantity'] ?? 0,
                'row_count' => $a['row_count'] ?? 1,
                'selling_price' => $a['selling_price'] ?? 0,
                'total_cost_ex_vat' => $a['total_cost_ex_vat'] ?? 0,
                'total_vat' => $a['total_vat'] ?? 0
            ];
        }
    }
    unset($row);
    return $reconcileData;
}

/**
 * Records the bill history of a user action (JobManager::recordBillHistory); a failure is logged and never fails the
 * action
 *
 * @return bool whether a row was written, so a payload built before it can be built again
 */
function recordBillHistory(JobManager $jobManager, int $jobId, string $event): bool
{
    try {
        return $jobManager->recordBillHistory($jobId, $event) > 0;
    } catch (\Throwable $e) {
        error_log("Bill history ({$event}) of Job {$jobId} not recorded: " . $e->getMessage());
        return false;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // ================= JOB MANAGEMENT ================= //
        case 'list_jobs':
            $jobManager = new JobManager();
            $jobs = $jobManager->listJobs();
            echo json_encode([
                'status' => 'success',
                'data' => $jobs
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'create_job':
            $jobManager = new JobManager();
            $name = $_POST['name'] ?? '';
            $job = $jobManager->createJob($name);
            echo json_encode([
                'status' => 'success',
                'data' => $job
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_delete_summary':
            $jobManager = new JobManager();
            $summary = $jobManager->deleteSummary(intval($_GET['job_id'] ?? 0));
            if ($summary === null) {
                throw new Exception('ไม่พบ Job นี้');
            }
            echo json_encode([
                'status' => 'success',
                'data' => $summary
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'delete_job':
            $jobManager = new JobManager();
            $jobId = intval($_POST['job_id'] ?? 0);
            $job = $jobManager->getJob($jobId);
            if (!$job) {
                throw new Exception('ไม่พบ Job นี้');
            }
            // The typed name is the only proof that a person asked for this; a call without it deletes nothing
            if (trim((string)($_POST['confirm_name'] ?? '')) !== trim((string)$job['name'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'ชื่อ Job ที่พิมพ์ไม่ตรงกับชื่อ Job นี้ จึงยังไม่ได้ลบ'
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                break;
            }
            $jobManager->deleteJob($jobId);
            echo json_encode([
                'status' => 'success',
                'message' => 'Job deleted successfully'
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'clear_job':
            $jobManager = new JobManager();
            $jobId = intval($_POST['job_id'] ?? 0);
            $jobManager->clearJobData($jobId);
            echo json_encode([
                'status' => 'success',
                'message' => 'Job data cleared successfully'
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'scan_job_files':
            $jobManager = new JobManager();
            $jobId = intval($_GET['job_id'] ?? 0);
            $scanResult = $jobManager->scanSourceFiles($jobId);
            echo json_encode([
                'status' => 'success',
                'data' => $scanResult
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'extract_job_batch':
            $jobManager = new JobManager();
            $jobId = intval($_POST['job_id'] ?? 0);
            $job = $jobManager->getJob($jobId);
            if (!$job) {
                throw new Exception("Job not found: " . $jobId);
            }

            $filesJson = $_POST['files'] ?? '[]';
            $fileList = json_decode($filesJson, true) ?: [];
            $overwrite = !empty($_POST['overwrite']);
            $replaceDuplicates = !empty($_POST['replace_duplicates']);

            $uploadDir = $jobManager->getJobUploadDir($job);
            $finishedDir = $jobManager->getJobFinishedDir($job);

            $extractor = new PdfExtractor();
            $processedCount = 0;
            $batchEntries = [];
            $knownRoots = $jobManager->existingInvoiceRoots($jobId);
            $requestFiles = [];
            $removeFilenames = [];
            $skippedDuplicates = [];

            foreach ($fileList as $item) {
                $relPath = is_array($item) ? ($item['rel_path'] ?? $item['filename'] ?? '') : (string)$item;
                if ($relPath === '') {
                    continue;
                }

                $baseName = basename($relPath);
                $srcPath = $uploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                if (!file_exists($srcPath)) {
                    $srcPath = $uploadDir . DIRECTORY_SEPARATOR . $baseName;
                }

                // If not in uploadDir, check if already in finishedDir (e.g. overwrite re-extraction)
                if (!file_exists($srcPath)) {
                    $finishedCandidate = $finishedDir . DIRECTORY_SEPARATOR . $baseName;
                    if (file_exists($finishedCandidate)) {
                        $srcPath = $finishedCandidate;
                    }
                }

                if (!is_file($srcPath)) {
                    continue;
                }

                $realSrc = realpath($srcPath);
                if ($realSrc === false
                    || !(FileManager::isSubpath($realSrc, (string)realpath($uploadDir))
                        || FileManager::isSubpath($realSrc, (string)realpath($finishedDir)))) {
                    continue;
                }

                // 1. Extract rows from PDF
                $rows = $extractor->extractDetailedRows($srcPath);

                // A bill already held under another file name is skipped, or replaces that file
                $rowRoots = [];
                foreach ($rows as $r) {
                    $root = JobManager::invoiceRoot((string)($r['tax_invoice'] ?? ''));
                    if ($root !== '') {
                        $rowRoots[$root] = true;
                    }
                }
                $duplicateOf = null;
                foreach (array_keys($rowRoots) as $root) {
                    if (isset($knownRoots[$root]) && $knownRoots[$root] !== $baseName) {
                        $duplicateOf = $knownRoots[$root];
                        break;
                    }
                }
                if ($duplicateOf !== null) {
                    if (!$replaceDuplicates) {
                        $skippedDuplicates[] = ['filename' => $baseName, 'duplicate_of' => $duplicateOf];
                        continue;
                    }
                    if (isset($requestFiles[$duplicateOf])) {
                        $batchEntries = array_values(array_filter($batchEntries, function ($entry) use ($duplicateOf) {
                            return $entry['filename'] !== $duplicateOf;
                        }));
                        $processedCount--;
                    } else {
                        $removeFilenames[$duplicateOf] = true;
                    }
                }
                foreach (array_keys($rowRoots) as $root) {
                    $knownRoots[$root] = $baseName;
                }
                $requestFiles[$baseName] = true;

                // 2. Move file from upload here -> finished (moving, not copying)
                $destPath = $finishedDir . DIRECTORY_SEPARATOR . $baseName;
                if (realpath($srcPath) !== realpath($destPath)) {
                    if (file_exists($destPath)) {
                        @unlink($destPath);
                    }
                    if (!@rename($srcPath, $destPath)) {
                        if (@copy($srcPath, $destPath)) {
                            @unlink($srcPath);
                        }
                    }
                    // Clean up empty parent subfolder inside upload here if file came from a subfolder
                    FileManager::pruneEmptyParents($srcPath, $uploadDir);
                }

                $storedPath = file_exists($destPath) ? $destPath : $srcPath;

                $batchEntries[] = [
                    'filename' => $baseName,
                    'storedPath' => $storedPath,
                    'items' => $rows
                ];
                $processedCount++;
            }

            // Clean up any remaining empty subdirectories inside upload here
            FileManager::cleanEmptySubdirectories($uploadDir);

            if (!empty($batchEntries)) {
                $extractedBatchRows = $jobManager->recordExtractedBatch($jobId, $batchEntries, $overwrite, array_keys($removeFilenames));
            } else {
                $extractedBatchRows = [];
            }

            echo json_encode([
                'status' => 'success',
                'processed_files' => $processedCount,
                'items_count' => count($extractedBatchRows),
                'skipped_duplicates' => $skippedDuplicates,
                'replaced_files' => array_keys($removeFilenames),
                'data' => $extractedBatchRows
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'get_job_items':
            $jobManager = new JobManager();
            $jobId = intval($_GET['job_id'] ?? 0);
            $items = $jobManager->getJobItems($jobId);
            echo json_encode([
                'status' => 'success',
                'count' => count($items),
                'data' => $items
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'delete_job_items':
            $jobManager = new JobManager();
            $jobId = intval($_POST['job_id'] ?? 0);
            $idsJson = $_POST['ids'] ?? '[]';
            $ids = json_decode($idsJson, true) ?: [];
            $deletedCount = $jobManager->deleteJobItems($jobId, $ids);
            $reconcileData = compactReconcilePayload($jobManager->reconcileJob($jobId));
            echo json_encode([
                'status' => 'success',
                'deleted_count' => $deletedCount,
                'job' => $jobManager->getJob($jobId),
                'reconcile' => $reconcileData
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        // ================= CREDIT NOTE ================= //
        case 'job_cn_scan':
            $jobManager = new JobManager();
            echo json_encode([
                'status' => 'success',
                'data' => $jobManager->scanCreditNotes(intval($_GET['job_id'] ?? 0))
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_extract_batch':
            $jobManager = new JobManager();
            $relPaths = [];
            foreach ((array)(json_decode($_POST['files'] ?? '[]', true) ?: []) as $item) {
                $relPath = is_array($item) ? ($item['rel_path'] ?? $item['filename'] ?? '') : (string)$item;
                if ($relPath !== '') {
                    $relPaths[] = (string)$relPath;
                }
            }
            $extracted = $jobManager->extractCreditNotes(intval($_POST['job_id'] ?? 0), $relPaths, !empty($_POST['replace_duplicates']));
            echo json_encode(['status' => 'success'] + $extracted, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_docs':
            $jobManager = new JobManager();
            $jobId = intval($_GET['job_id'] ?? 0);
            $docs = $jobManager->getCreditNotes($jobId);
            echo json_encode([
                'status' => 'success',
                'count' => count($docs),
                'counts' => $jobManager->creditNoteCounts($jobId),
                'data' => $docs
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_clear':
            $jobManager = new JobManager();
            $cleared = $jobManager->clearCreditNotes(intval($_POST['job_id'] ?? 0));
            echo json_encode([
                'status' => 'success',
                'deleted_files' => $cleared['files'],
                'deleted_documents' => $cleared['documents']
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_import_asn':
            $compare = (new JobManager())->creditNoteCompare();
            $answer = $compare->importAsn(
                intval($_POST['job_id'] ?? 0),
                (string)($_POST['asn_filename'] ?? ''),
                array_values(array_filter((array)(json_decode($_POST['records'] ?? '[]', true) ?: []), 'is_array')),
                !isset($_POST['append']) || !empty($_POST['append']),
                ($_POST['duplicate_mode'] ?? 'skip') === 'replace' ? 'replace' : 'skip',
                ($_POST['same_file_mode'] ?? 'replace') === 'skip' ? 'skip' : 'replace',
                !empty($_POST['reconcile'])
            );
            echo json_encode(['status' => 'success'] + $answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_asn_overlap':
            $compare = (new JobManager())->creditNoteCompare();
            $answer = $compare->asnOverlap(
                intval($_POST['job_id'] ?? 0),
                (string)($_POST['asn_filename'] ?? ''),
                array_values(array_filter((array)(json_decode($_POST['records'] ?? '[]', true) ?: []), 'is_array'))
            );
            echo json_encode(['status' => 'success'] + $answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_asn_records':
            $compare = (new JobManager())->creditNoteCompare();
            echo json_encode(['status' => 'success'] + $compare->asnRecords(intval($_GET['job_id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_delete_asn':
            $compare = (new JobManager())->creditNoteCompare();
            $answer = $compare->deleteAsn(intval($_POST['job_id'] ?? 0), (array)(json_decode($_POST['ids'] ?? '[]', true) ?: []));
            echo json_encode(['status' => 'success'] + $answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_reconcile':
            $compare = (new JobManager())->creditNoteCompare();
            echo json_encode(['status' => 'success'] + $compare->reconcile(intval($_GET['job_id'] ?? 0), !empty($_GET['force'])), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_cn_dashboard':
            $compare = (new JobManager())->creditNoteCompare();
            echo json_encode(['status' => 'success', 'data' => $compare->dashboard(intval($_GET['job_id'] ?? 0))], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        // ================= VBRP ================= //
        case 'job_vbrp_upload':
            $jobManager = new JobManager();
            $uploaded = $jobManager->saveVbrpUploads(intval($_POST['job_id'] ?? 0), $_FILES['files'] ?? []);
            echo json_encode(['status' => 'success'] + $uploaded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_vbrp_scan':
            $jobManager = new JobManager();
            echo json_encode([
                'status' => 'success',
                'data' => $jobManager->scanVbrp(intval($_GET['job_id'] ?? 0))
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_vbrp_extract_batch':
            $jobManager = new JobManager();
            $relPaths = [];
            foreach ((array)(json_decode($_POST['files'] ?? '[]', true) ?: []) as $item) {
                $relPath = is_array($item) ? ($item['rel_path'] ?? $item['filename'] ?? '') : (string)$item;
                if ($relPath !== '') {
                    $relPaths[] = (string)$relPath;
                }
            }
            $extracted = $jobManager->extractVbrp(intval($_POST['job_id'] ?? 0), $relPaths, !empty($_POST['replace_existing']));
            echo json_encode(['status' => 'success'] + $extracted, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_vbrp_rows':
            $jobManager = new JobManager();
            $jobId = intval($_GET['job_id'] ?? 0);
            $rows = $jobManager->getVbrpRows($jobId);
            echo json_encode([
                'status' => 'success',
                'count' => count($rows),
                'counts' => $jobManager->vbrpCounts($jobId),
                'files' => $jobManager->getVbrpFiles($jobId),
                'data' => $rows
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_vbrp_import':
            $file = $_FILES['file'] ?? ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE];
            $uploadError = (int)$file['error'];
            $tooLarge = VbrpStore::sizeRefusal((int)($_SERVER['CONTENT_LENGTH'] ?? 0), empty($_POST) && empty($_FILES), $uploadError, (string)ini_get('post_max_size'), (string)ini_get('upload_max_filesize'));
            if ($tooLarge !== null) {
                throw new Exception($tooLarge);
            }
            $jobManager = new JobManager();
            if ($uploadError === UPLOAD_ERR_OK && !is_uploaded_file((string)$file['tmp_name'])) {
                $uploadError = UPLOAD_ERR_NO_FILE;
            }
            $imported = $jobManager->importVbrp(intval($_POST['job_id'] ?? 0), (string)$file['tmp_name'], (string)$file['name'], $uploadError);
            recordBillHistory($jobManager, intval($_POST['job_id'] ?? 0), 'vbrp_import');
            echo json_encode(['status' => 'success'] + $imported, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'job_vbrp_clear':
            $jobManager = new JobManager();
            $cleared = $jobManager->clearVbrp(intval($_POST['job_id'] ?? 0));
            recordBillHistory($jobManager, intval($_POST['job_id'] ?? 0), 'vbrp_clear');
            echo json_encode([
                'status' => 'success',
                'deleted_files' => $cleared['files'],
                'deleted_rows' => $cleared['rows']
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        // ================= ASN IMPORT & RECONCILIATION ================= //
        case 'import_asn':
            $jobManager = new JobManager();
            $jobId = intval($_POST['job_id'] ?? 0);
            $asnFilename = $_POST['asn_filename'] ?? 'ASN_Upload.xlsx';
            $recordsJson = $_POST['records'] ?? '[]';
            $records = json_decode($recordsJson, true) ?: [];
            $append = !isset($_POST['append']) || !empty($_POST['append']);
            $doReconcile = !empty($_POST['reconcile']);

            if (empty($records)) {
                throw new Exception("No records to import from ASN file");
            }

            $duplicateMode = ($_POST['duplicate_mode'] ?? 'skip') === 'replace' ? 'replace' : 'skip';
            $sameFileMode = ($_POST['same_file_mode'] ?? 'replace') === 'skip' ? 'skip' : 'replace';
            $savedCount = $jobManager->saveAsnRecords($jobId, $asnFilename, $records, $append, $duplicateMode, $sameFileMode);
            $reconcileData = null;
            if ($doReconcile) {
                $reconcileData = compactReconcilePayload($jobManager->reconcileJob($jobId));
            }
            if (recordBillHistory($jobManager, $jobId, 'asn_import') && $doReconcile) {
                $reconcileData = compactReconcilePayload($jobManager->reconcileJob($jobId));
            }
            $jobRow = $jobManager->getJob($jobId);

            echo json_encode([
                'status' => 'success',
                'imported_records' => $savedCount,
                'skipped_invoices' => $jobManager->lastAsnImport['skipped_invoices'],
                'replaced_invoices' => $jobManager->lastAsnImport['replaced_invoices'],
                'skipped_same_file' => $jobManager->lastAsnImport['skipped_same_file'],
                'replaced_same_file' => $jobManager->lastAsnImport['replaced_same_file'],
                'asn_filename' => $jobRow['asn_filename'] ?? $asnFilename,
                'asn_reconciled' => (int)($jobRow['asn_reconciled'] ?? 0),
                'asn_counts' => $jobManager->asnCounts($jobId),
                'reconcile' => $reconcileData,
                'raw_records' => $doReconcile ? null : $jobManager->getAsnRecords($jobId)
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'asn_overlap':
            $jobManager = new JobManager();
            $jobId = intval($_POST['job_id'] ?? 0);
            $asnFilename = $_POST['asn_filename'] ?? 'ASN_Upload.xlsx';
            $records = json_decode($_POST['records'] ?? '[]', true) ?: [];
            $invoices = array_map(function ($r) {
                return trim((string)($r['invoice_no'] ?? ''));
            }, $records);
            $overlap = $jobManager->findAsnOverlap($jobId, $asnFilename, $invoices);
            echo json_encode([
                'status' => 'success',
                'same_file' => $jobManager->describeStoredAsnFile($jobId, $asnFilename, $records),
                'overlap_count' => count($overlap),
                'files' => array_values(array_unique(array_merge(...array_values($overlap ?: [[]])))),
                'sample' => array_slice(array_keys($overlap), 0, 5)
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'get_asn_records':
            $jobManager = new JobManager();
            $jobId = intval($_GET['job_id'] ?? 0);
            $records = $jobManager->getAsnRecords($jobId);
            $jobRow = $jobManager->getJob($jobId);
            echo json_encode([
                'status' => 'success',
                'count' => count($records),
                'asn_filename' => $jobRow['asn_filename'] ?? null,
                'asn_reconciled' => (int)($jobRow['asn_reconciled'] ?? 0),
                'data' => $records
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'delete_asn_records':
            $jobManager = new JobManager();
            $jobId = intval($_POST['job_id'] ?? 0);
            $idsJson = $_POST['ids'] ?? '[]';
            $ids = json_decode($idsJson, true) ?: [];
            $deletedCount = $jobManager->deleteAsnRecords($jobId, $ids);
            $jobRow = $jobManager->getJob($jobId);
            $isReconciled = !empty($jobRow['asn_reconciled']);
            $reconcileData = null;
            if ($isReconciled) {
                $reconcileData = compactReconcilePayload($jobManager->reconcileJob($jobId));
            }
            if (recordBillHistory($jobManager, $jobId, 'asn_delete') && $isReconciled) {
                $reconcileData = compactReconcilePayload($jobManager->reconcileJob($jobId));
            }
            $jobRow = $jobManager->getJob($jobId);
            echo json_encode([
                'status' => 'success',
                'deleted_count' => $deletedCount,
                'asn_filename' => $jobRow['asn_filename'] ?? null,
                'asn_reconciled' => (int)($jobRow['asn_reconciled'] ?? 0),
                'asn_counts' => $jobManager->asnCounts($jobId),
                'reconcile' => $reconcileData,
                'raw_records' => $isReconciled ? null : $jobManager->getAsnRecords($jobId)
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'get_job_reconcile':
            $jobManager = new JobManager();
            $jobId = intval($_GET['job_id'] ?? 0);
            $force = !empty($_GET['force']);
            $jobRow = $jobManager->getJob($jobId);
            $isReconciled = !empty($jobRow['asn_reconciled']);

            if ($force || $isReconciled) {
                // Only the compare button sends event=compare; a page load never records
                if (($_GET['event'] ?? '') === 'compare') {
                    recordBillHistory($jobManager, $jobId, 'compare');
                }
                $reconcileData = compactReconcilePayload($jobManager->reconcileJob($jobId));
                $jobRow = $jobManager->getJob($jobId);
                echo json_encode([
                    'status' => 'success',
                    'asn_filename' => $jobRow['asn_filename'] ?? null,
                    'asn_reconciled' => 1,
                    'asn_counts' => $jobManager->asnCounts($jobId),
                    'data' => $reconcileData
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } else {
                $rawRecords = $jobManager->getAsnRecords($jobId);
                echo json_encode([
                    'status' => 'success',
                    'asn_filename' => $jobRow['asn_filename'] ?? null,
                    'asn_reconciled' => 0,
                    'asn_counts' => $jobManager->asnCounts($jobId),
                    'raw_records' => $rawRecords,
                    'data' => null
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            break;

        case 'get_job_dashboard':
            $jobManager = new JobManager();
            $jobId = intval($_GET['job_id'] ?? 0);
            if (!$jobManager->getJob($jobId)) {
                throw new Exception('ไม่พบ Job นี้');
            }
            echo json_encode([
                'status' => 'success',
                'data' => $jobManager->getInvoiceDashboard($jobId)
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'fix_invoice':
            $jobId = intval($_POST['job_id'] ?? $_GET['job_id'] ?? 0);
            $invoiceNo = trim((string)($_POST['invoice_no'] ?? $_GET['invoice_no'] ?? ''));
            if ($jobId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid job_id']);
                break;
            }
            $pdo = \App\Database::getConnection();
            $fixer = new \App\PdfFixer($pdo);
            $fixResult = $fixer->fixJob($jobId, $invoiceNo !== '' ? $invoiceNo : null);
            recordBillHistory(new JobManager(), $jobId, 'fix');
            echo json_encode([
                'status' => 'success',
                'data' => $fixResult
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'open_folder':
            if (!FileManager::isSameMachine((string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['SERVER_ADDR'] ?? ''))) {
                throw new Exception('เปิดโฟลเดอร์ได้เฉพาะเมื่อเปิดโปรแกรมบนเครื่องที่รันเซิร์ฟเวอร์นี้เท่านั้น');
            }
            $jobManager = new JobManager();
            $target = (string)($_POST['target'] ?? 'job');
            if ($target === 'root') {
                $folder = Config::getMyfilePath();
            } else {
                $job = $jobManager->getJob(intval($_POST['job_id'] ?? 0));
                if (!$job || !$jobManager->jobFolderExists($job)) {
                    throw new Exception('ไม่พบโฟลเดอร์ของ Job นี้');
                }
                $folders = [
                    'job' => $jobManager->getJobFolderDir($job, false),
                    'ti' => $jobManager->getJobTaxInvoiceDir($job),
                    'upload' => $jobManager->getJobUploadDir($job),
                    'finished' => $jobManager->getJobFinishedDir($job),
                    'print' => $jobManager->getJobPrintDir($job),
                    'round' => $jobManager->getJobPrintRoundDir($job, intval($_POST['round'] ?? 0)),
                    'cn' => $jobManager->getJobCreditNoteDir($job),
                    'cn_upload' => $jobManager->getJobCnUploadDir($job),
                    'cn_finished' => $jobManager->getJobCnFinishedDir($job),
                    'vbrp' => $jobManager->getJobVbrpDir($job, false),
                    'vbrp_upload' => $jobManager->getJobVbrpUploadDir($job, false),
                    'vbrp_finished' => $jobManager->getJobVbrpFinishedDir($job, false),
                    'cover' => $target === 'cover' ? $jobManager->getJobCoverDir($job) : '',
                ];
                if (!isset($folders[$target])) {
                    throw new Exception('ไม่รู้จักโฟลเดอร์ที่ขอ');
                }
                $folder = $folders[$target];
            }
            echo json_encode(['status' => 'success'] + FileManager::openInExplorer($folder), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'cover_preview':
            $jobId = intval($_GET['job_id'] ?? 0);
            $preview = (new CoverSheet(new JobManager()))->preview($jobId);
            echo json_encode(['status' => 'success', 'data' => $preview], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'cover_generate':
            $jobId = intval($_POST['job_id'] ?? 0);
            @set_time_limit(300);
            $made = (new CoverSheet(new JobManager()))->generate($jobId, [
                'contact' => (string)($_POST['contact'] ?? ''),
                'mobile' => (string)($_POST['mobile'] ?? ''),
                'case_label' => (string)($_POST['case_label'] ?? ''),
                'date' => (string)($_POST['date'] ?? ''),
            ]);
            echo json_encode(['status' => 'success', 'data' => $made], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'print_plan':
            $jobId = intval($_GET['job_id'] ?? 0);
            $plan = (new PrintManager(new JobManager()))->plan($jobId);
            unset($plan['ready']);
            echo json_encode(['status' => 'success', 'data' => $plan], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'print_start':
            $jobId = intval($_POST['job_id'] ?? 0);
            $started = (new PrintManager(new JobManager()))->start($jobId);
            unset($started['plan']['ready']);
            echo json_encode(['status' => 'success', 'data' => $started], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'print_chunk':
            $jobId = intval($_POST['job_id'] ?? 0);
            $chunk = (new PrintManager(new JobManager()))->processChunk($jobId, (string)($_POST['token'] ?? ''), intval($_POST['offset'] ?? 0), 40);
            echo json_encode(['status' => 'success', 'data' => $chunk], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'print_finish':
            $jobId = intval($_POST['job_id'] ?? 0);
            $summary = (new PrintManager(new JobManager()))->finish($jobId, (string)($_POST['token'] ?? ''));
            echo json_encode(['status' => 'success', 'data' => $summary], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'fix_all_invoices':
            $jobId = intval($_POST['job_id'] ?? $_GET['job_id'] ?? 0);
            if ($jobId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid job_id']);
                break;
            }
            $pdo = \App\Database::getConnection();
            $fixer = new \App\PdfFixer($pdo);
            $fixResult = $fixer->fixJob($jobId, null);
            recordBillHistory(new JobManager(), $jobId, 'fix');
            echo json_encode([
                'status' => 'success',
                'data' => $fixResult
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        // ================= LEGACY / BACKWARD COMPATIBLE ================= //
        case 'get_stats':
            $only = $_GET['only'] ?? '';
            $jobId = intval($_GET['job_id'] ?? 0);
            $jobManager = new JobManager();
            if ($jobId > 0 && ($job = $jobManager->getJob($jobId))) {
                $uploadDir = $jobManager->getJobUploadDir($job, false);
                $myfileCount = is_dir($uploadDir) ? count(FileManager::getPdfFiles($uploadDir)) : 0;
                $invoices = [];
                $cns = [];
            } else {
                $myfiles = FileManager::getPdfFiles(Config::getMyfilePath());
                $myfileCount = count($myfiles);
                $invoices = $only === 'myfile' ? [] : FileManager::getPdfFiles(Config::getInvoicePath());
                $cns = $only === 'myfile' ? [] : FileManager::getPdfFiles(Config::getCnPath());
            }

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'myfile_count' => $myfileCount,
                    'invoice_count' => count($invoices),
                    'cn_count' => count($cns)
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'list_files':
            $folder = $_GET['folder'] ?? 'myfile';
            $dir = getFolderPath($folder);

            $files = FileManager::getPdfFiles($dir);
            $relativeFiles = array_map(function($f) use ($dir) {
                return ltrim(str_replace($dir, '', $f), DIRECTORY_SEPARATOR);
            }, $files);

            echo json_encode([
                'status' => 'success',
                'total' => count($relativeFiles),
                'files' => $relativeFiles,
                'folder' => $folder
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'extract_cn_batch':
            $folder = $_POST['folder'] ?? 'myfile';
            $filesJson = $_POST['files'] ?? '[]';
            $fileList = json_decode($filesJson, true) ?: [];
            $dir = getFolderPath($folder);

            $extractor = new PdfExtractor();
            $allDocs = [];

            foreach ($fileList as $relativePath) {
                $fullPath = $dir . DIRECTORY_SEPARATOR . $relativePath;
                if (file_exists($fullPath)) {
                    $docs = $extractor->extractCnDocuments($fullPath);
                    foreach ($docs as $d) {
                        $allDocs[] = $d;
                    }
                }
            }

            echo json_encode([
                'status' => 'success',
                'count' => count($allDocs),
                'processed_files' => count($fileList),
                'data' => $allDocs
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        // ================= แก้ไขวันที่ PDF (date_editor.php) ================= //
        case 'date_edit_scan':
            echo json_encode(['status' => 'success', 'data' => (new \App\DateEditStore())->scan()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'date_edit_file':
            @set_time_limit(120);
            $result = (new \App\DateEditStore())->process(
                (string)($_POST['path'] ?? ''),
                \App\DateEditStore::parseDate((string)($_POST['date'] ?? ''))
            );
            echo json_encode(['status' => 'success', 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'date_edit_clear':
            $store = new \App\DateEditStore();
            $target = (string)($_POST['target'] ?? '');
            $deleted = $store->clear($target);
            echo json_encode([
                'status' => 'success',
                'deleted' => $deleted,
                'folder' => \App\DateEditStore::displayPath($target),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'date_edit_open':
            if (!FileManager::isSameMachine((string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['SERVER_ADDR'] ?? ''))) {
                throw new Exception('เปิดโฟลเดอร์ได้เฉพาะเมื่อเปิดโปรแกรมบนเครื่องที่รันเซิร์ฟเวอร์นี้เท่านั้น');
            }
            $folder = (new \App\DateEditStore())->dir((string)($_POST['target'] ?? ''));
            echo json_encode(['status' => 'success'] + FileManager::openInExplorer($folder), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action parameter'
            ]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    $message = $e->getMessage();
    if (!($e instanceof Exception) || $message === '') {
        // A PHP Error (TypeError, undefined function on an older PHP) says where it happened
        $message = get_class($e) . ': ' . $message . ' in ' . basename($e->getFile()) . ' on line ' . $e->getLine();
    }
    error_log('api.php ' . ($_GET['action'] ?? $_POST['action'] ?? '') . ': ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo apiJsonError($message);
}
