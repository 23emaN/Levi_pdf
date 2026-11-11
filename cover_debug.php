<?php
/**
 * ไล่หาว่าการเตรียมใบปะหน้า (api.php?action=cover_preview) พังที่ขั้นไหน: ทำทีละขั้นแล้วพิมพ์ผลทันที
 * เปิดที่ http://127.0.0.1/{ชื่อโฟลเดอร์}/cover_debug.php?job_id=29  ถ้าหน้าหยุดกลางทาง ขั้นสุดท้ายที่เห็นคือขั้นที่พัง
 * หน้านี้ไม่แก้ไขข้อมูล (ทำแบบเดียวกับตอนเปิดหน้าใบปะหน้า) ลบทิ้งได้เมื่อแก้เสร็จ
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('html_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

function step($label)
{
    echo "\n>> " . $label . ' ... ';
    flush();
}

function ok($detail = '')
{
    echo 'OK' . ($detail !== '' ? ' (' . $detail . ')' : '') . '  [mem ' . round(memory_get_usage(true) / 1048576, 1) . ' MB]';
    flush();
}

function show_error($e)
{
    $msg = $e->getMessage();
    if (!preg_match('//u', $msg) && function_exists('iconv')) {
        $msg = (string)@iconv('CP874', 'UTF-8//IGNORE', $msg) . '   [ข้อความเดิมไม่ใช่ UTF-8: ' . bin2hex(substr($e->getMessage(), 0, 80)) . ']';
    }
    echo "FAILED\n   " . get_class($e) . ': ' . $msg . "\n   at " . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
    flush();
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR))) {
        echo "\n\nFATAL: " . $error['message'] . "\n   at " . $error['file'] . ':' . $error['line'] . "\n";
    }
    echo "\n-- จบ --\n";
});

$jobId = (int)($_GET['job_id'] ?? 0);
echo "cover_debug job_id=$jobId\n";
echo 'PHP ' . PHP_VERSION . ' ' . PHP_SAPI . ' ' . (PHP_INT_SIZE * 8) . "bit\n";
foreach (array('pdo_sqlite', 'mbstring', 'iconv', 'zlib', 'json', 'gd', 'fileinfo') as $ext) {
    echo '  ext ' . $ext . ': ' . (extension_loaded($ext) ? 'yes' : 'NO') . "\n";
}
echo '  memory_limit=' . ini_get('memory_limit') . ' max_execution_time=' . ini_get('max_execution_time')
    . ' opcache=' . (function_exists('opcache_get_status') && @opcache_get_status(false) ? 'on' : 'off') . "\n";
echo '  api.php has apiJsonError (copied new api.php?): ' . (strpos((string)@file_get_contents(__DIR__ . '/api.php'), 'function apiJsonError') !== false ? 'yes' : 'NO') . "\n";

try {
    step('autoload');
    require_once __DIR__ . '/vendor/autoload.php';
    ok();

    step('database');
    $db = \App\Database::getConnection();
    ok('SQLite ' . $db->query('select sqlite_version()')->fetchColumn());

    $jobs = new \App\JobManager();

    step('getJob');
    $job = $jobs->getJob($jobId);
    if (!$job) {
        throw new RuntimeException('ไม่พบ Job ' . $jobId);
    }
    ok($job['name'] . ', asn_reconciled=' . var_export($job['asn_reconciled'] ?? null, true));

    if ((int)($job['asn_reconciled'] ?? 0) === 1) {
        step('reconcileJob');
        $results = $jobs->reconcileJob($jobId)['results'];
        ok(count($results) . ' rows');

        step('invoiceStores / invoiceStatuses');
        $stores = \App\AsnReconciler::invoiceStores($results);
        $statuses = \App\AsnReconciler::invoiceStatuses($results);
        ok(count($stores) . ' stores, ' . count($statuses) . ' invoices');
    } else {
        echo "\n>> reconcileJob: ข้าม (Job ยังไม่ได้เทียบ ASN)";
    }

    step('creditNoteCompare()->dashboard');
    $dash = $jobs->creditNoteCompare()->dashboard($jobId);
    ok('compared=' . var_export($dash['compared'] ?? null, true) . ', ' . count($dash['documents'] ?? array()) . ' documents');

    step('getCreditNotes');
    $cns = $jobs->getCreditNotes($jobId);
    ok(count($cns) . ' credit notes');

    step('StoreNames');
    $names = new \App\StoreNames();
    $names->learnFromCreditNotes($cns);
    ok();

    step('getJobCoverDir');
    ok($jobs->getJobCoverDir($jobId, false));

    step('CoverSheet->preview (ทั้งหมด)');
    $preview = (new \App\CoverSheet($jobs))->preview($jobId);
    ok(count($preview['stores']) . ' stores, ' . $preview['totals']['pages'] . ' pages');

    step('json_encode ผลลัพธ์');
    $json = json_encode(array('status' => 'success', 'data' => $preview), JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo 'FAILED: ' . json_last_error_msg();
    } else {
        ok(strlen($json) . ' bytes');
    }
} catch (Throwable $e) {
    show_error($e);
}
