<?php
[, $root, $pdfList, $asnJson, $out] = $argv;
$swap = isset($argv[5]) ? json_decode(file_get_contents($argv[5]), true) : null;
putenv('PDF_READER_ROOT=' . $root);
require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\FixLog;
use App\FixPlan;
use App\JobManager;
use App\PdfExtractor;
use App\PdfFixer;
use App\PrintManager;

$jm = new JobManager();
$pdo = Database::getConnection();
$job = $jm->createJob('V');
$finished = $jm->getJobFinishedDir($job);

$pdfs = json_decode(file_get_contents($pdfList), true);
sort($pdfs);

$extractor = new PdfExtractor();
$rows = [];
$entries = [];
foreach ($pdfs as $path) {
    $name = basename($path);
    copy($path, $finished . '/' . $name);
    $items = $extractor->extractDetailedRows($finished . '/' . $name);
    foreach ($items as $item) {
        $item['filename'] = $name;
        $rows[] = $item;
    }
    $entries[] = ['filename' => $name, 'storedPath' => $finished . '/' . $name, 'items' => $items];
}
$jm->recordExtractedBatch($job['id'], $entries, true);
if ($swap) {
    [$file, $pageA, $pageB] = $swap;
    $fields = array_keys(FixPlan::CLEARED_FOOTER);
    $read = $pdo->prepare('SELECT ' . implode(', ', $fields) . ' FROM job_items WHERE job_id = ? AND filename = ? AND page_no = ? LIMIT 1');
    $footers = [];
    foreach ([$pageA, $pageB] as $page) {
        $read->execute([$job['id'], $file, $page]);
        $footers[$page] = $read->fetch(PDO::FETCH_ASSOC);
    }
    $write = $pdo->prepare('UPDATE job_items SET ' . implode(' = ?, ', $fields) . ' = ? WHERE job_id = ? AND filename = ? AND page_no = ?');
    $write->execute(array_merge(array_values($footers[$pageB]), [$job['id'], $file, $pageA]));
    $write->execute(array_merge(array_values($footers[$pageA]), [$job['id'], $file, $pageB]));
}
$jm->saveAsnRecords($job['id'], 'truth.xlsx', json_decode(file_get_contents($asnJson), true));
$asnRows = $pdo->query('SELECT invoice_no, ean_upc, quantity FROM job_asn_records WHERE job_id = ' . (int)$job['id'])->fetchAll(PDO::FETCH_ASSOC);
$invoices = array_values(array_unique(array_column($asnRows, 'invoice_no')));

$planBefore = new FixPlan($asnRows, $jm->getJobItems($job['id']));
$before = $jm->reconcileJob($job['id']);
$dashboardBefore = $jm->getInvoiceDashboard($job['id'])['invoices'];
$fix = (new PdfFixer($pdo))->fixJob($job['id']);
$stepsAfter = $pdo->query('SELECT kind, filename, page_no AS page, invoice_no AS invoice, from_value AS "from" FROM job_invoice_fix_steps WHERE job_id = ' . (int)$job['id'])->fetchAll(PDO::FETCH_ASSOC);
$planAfter = new FixPlan($asnRows, $jm->getJobItems($job['id']));
$after = $jm->reconcileJob($job['id']);
$print = (new PrintManager($jm))->runAll($job['id']);

file_put_contents($out, json_encode([
    'rows' => $rows,
    'before' => $before,
    'dashboard_before' => $dashboardBefore,
    'after' => $after,
    'fix' => $fix,
    'plan_before' => ['files' => $planBefore->files(), 'steps' => $planBefore->steps()],
    'steps_after' => $stepsAfter,
    'plan_after' => ['steps' => $planAfter->steps(), 'texts' => $planAfter->describe($invoices, (new FixLog($pdo))->splitDone($job['id']))],
    'items_after' => $jm->getJobItems($job['id']),
    'finished_dir' => $finished,
    'print' => $print,
    'print_dir' => $jm->getJobPrintDir($job, false),
], JSON_UNESCAPED_UNICODE));
