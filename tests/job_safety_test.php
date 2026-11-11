<?php
// Run: php tests/job_safety_test.php
// Runs against a throwaway root (PDF_READER_ROOT), never touches data/ or myfile/.

$mode = $argv[1] ?? '';

if ($mode === '--api') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = ['action' => $request['action']] + ($request['get'] ?? []);
    $_POST = $request['post'];
    require __DIR__ . '/../api.php';
    exit;
}

if ($mode === '--page') {
    $_GET = json_decode(file_get_contents($argv[2]), true);
    require __DIR__ . '/../workspace.php';
    exit;
}

if ($mode === '--index') {
    require __DIR__ . '/../index.php';
    exit;
}

if ($mode !== '--run') {
    $sample = __DIR__ . '/fixtures/CDS ZFP (ZKE)/RM Document-Customer Billing(PDF)-0093247359 3.pdf';
    if (!is_file($sample)) {
        fwrite(STDERR, "SKIP: sample PDF not found: $sample
");
        exit(2);
    }
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_test_' . bin2hex(random_bytes(4));
    mkdir($root . '/data', 0777, true);
    mkdir($root . '/myfile', 0777, true);
    putenv('PDF_READER_ROOT=' . $root);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg($sample), $code);
    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($tree as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
    exit($code);
}

$sample = $argv[2];
$root = getenv('PDF_READER_ROOT');

require __DIR__ . '/../vendor/autoload.php';

use App\AsnReconciler;
use App\Database;
use App\FileManager;
use App\FixLog;
use App\FixPlan;
use App\JobManager;
use App\PdfExtractor;
use App\PdfFixer;
use App\PrintManager;

$jm = new JobManager();
$pdo = Database::getConnection();
$failures = 0;

const FORBIDDEN_REMARK_WORDS = '/ต้องกด|ปุ่ม|สกัด|footer/iu';

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function api(string $action, array $post = [], array $get = []): array
{
    $requestFile = tempnam(sys_get_temp_dir(), 'api_req_');
    file_put_contents($requestFile, json_encode(['action' => $action, 'post' => $post, 'get' => $get]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($requestFile);
    $out = (string)shell_exec($cmd);
    unlink($requestFile);
    return json_decode($out, true) ?: [];
}

function renderWorkspace(array $get): string
{
    $requestFile = tempnam(sys_get_temp_dir(), 'page_req_');
    file_put_contents($requestFile, json_encode($get));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --page ' . escapeshellarg($requestFile));
    unlink($requestFile);
    return $out;
}

$job = $jm->createJob('Extract flow');
$upload = $jm->getJobUploadDir($job);
mkdir($upload . '/sub');
copy($sample, $upload . '/sub/a.pdf');
$res = api('extract_job_batch', ['job_id' => $job['id'], 'files' => json_encode([['filename' => 'a.pdf', 'rel_path' => 'sub/a.pdf']])]);
$finished = $jm->getJobFinishedDir($job);
check('extraction copies the PDF from upload/<Job>/invoice/pdf to finished and leaves the original where it was', is_file($finished . '/a.pdf') && is_file($upload . '/sub/a.pdf'));
check('extraction leaves the subfolders of upload/<Job>/invoice/pdf alone', is_dir($upload . '/sub'));
check('extraction records the rows', ($res['items_count'] ?? 0) === 30, 'items=' . ($res['items_count'] ?? '?'));

$job = $jm->createJob('Traversal');
mkdir($root . '/outside');
copy($sample, $root . '/outside/decoy.pdf');
$res = api('extract_job_batch', ['job_id' => $job['id'], 'files' => json_encode(['../../../outside/decoy.pdf'])]);
check('a path outside the job folders is not processed or moved', is_file($root . '/outside/decoy.pdf') && ($res['processed_files'] ?? -1) === 0);

$job = $jm->createJob('Clear keeps uploads');
$upload = $jm->getJobUploadDir($job);
copy($sample, $upload . '/pending.pdf');
$jm->clearJobData($job['id']);
check('clearJobData keeps the files of upload/<Job>/invoice/pdf', is_file($upload . '/pending.pdf'));

$job = $jm->createJob('Clear empty');
$jm->clearJobData($job['id']);
check('clearJobData removes the folder when nothing is waiting', !is_dir($jm->getJobFolderDir($job, false)));

$job = $jm->createJob('Counters');
$pdo->exec('UPDATE jobs SET asn_pending_fix_count = 7, asn_matched_count = 1, asn_mismatch_count = 2, asn_reconciled = 1 WHERE id = ' . $job['id']);
$jm->clearJobData($job['id']);
$row = $jm->getJob($job['id']);
check('clearJobData resets every ASN counter', (int)$row['asn_pending_fix_count'] === 0 && (int)$row['asn_matched_count'] === 0 && (int)$row['asn_mismatch_count'] === 0);

$jm->createJob('Dup Name');
$rejected = false;
try {
    $jm->createJob('dup name');
} catch (\RuntimeException $e) {
    $rejected = true;
}
check('a second job with the same folder name is rejected', $rejected);

$job = $jm->createJob('Fix:Special');
$finished = $jm->getJobFinishedDir($job);
copy($sample, $finished . '/sample.pdf');
$rows = (new PdfExtractor())->extractDetailedRows($finished . '/sample.pdf');
$entries = [['filename' => 'sample.pdf', 'storedPath' => $finished . '/sample.pdf', 'items' => $rows]];
$jm->recordExtractedBatch($job['id'], $entries, true);
$pdo->exec("UPDATE job_files SET file_path = '' WHERE job_id = " . $job['id']);
$asn = [];
foreach ($rows as $r) {
    $page = (int)$r['page_no'];
    $asn[] = [
        'invoice_no' => $page <= 2 ? '93247359-1' : ($page === 3 ? '93247359-2' : '93247359-3'),
        'ean_upc' => $r['barcode'],
        'quantity' => $r['units'],
        'dest_store_no' => '',
        'selling_price' => 1,
        'total_cost_ex_vat' => 1,
    ];
}
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
(new PdfFixer($pdo))->fixJob($job['id']);
check('PdfFixer finds a special-character job folder and splits 3 invoices', count(glob($finished . '/93247359-*.pdf')) === 3);

function reconcileOne(JobManager $jm, string $label, float $asnCost, int $asnQty, string $pdfExcl = '750.00'): array
{
    static $n = 0;
    $n++;
    $job = $jm->createJob("Reconcile $label $n");
    $inv = "9000000$n-1";
    $jm->saveAsnRecords($job['id'], 't.xlsx', [[
        'invoice_no' => $inv, 'ean_upc' => '8850000000001', 'quantity' => $asnQty,
        'dest_store_no' => '', 'selling_price' => 100, 'total_cost_ex_vat' => $asnCost, 'total_vat' => 52.5,
    ]]);
    $pdfItems = [[
        'tax_invoice' => $inv, 'page_no' => 1, 'barcode' => '8850000000001', 'units' => 1, 'amount' => 1070,
        'total_amount' => '1,070.00', 'amt_excl_vat' => $pdfExcl, 'vat_amount' => '52.50',
        'ship_to_party' => '', 'address' => '',
    ]];
    $result = (new AsnReconciler(Database::getConnection()))->reconcile($job['id'], $pdfItems, $job);
    return $result['results'][0];
}

check('ASN cost after discount (= PDF footer) matches', reconcileOne($jm, 'after', 750.00, 1)['status'] === 'MATCH');
$listPrice = reconcileOne($jm, 'before', 1000.00, 1);
check(
    'ASN cost equal to the list price / 1.07 but not to the PDF footer is wrong now, told as totals that do not match',
    [$listPrice['status'], $listPrice['reason'], $listPrice['action']] === ['MISMATCH', 'ยอดรวมไม่ตรง (PDF: 750.00 / ASN: 1,000.00)', ''],
    json_encode($listPrice, JSON_UNESCAPED_UNICODE)
);
check('ASN cost equal to neither footer nor list price is a mismatch', reconcileOne($jm, 'wrong', 900.00, 1)['status'] === 'MISMATCH');
check('a quantity difference is a mismatch even when cost matches', reconcileOne($jm, 'qty', 750.00, 2)['status'] === 'MISMATCH');

// 13,028.05 - 13,027.05 lands a hair above 1.00 in floating point.
$amountCases = [
    'PDF over by 1.00' => ['13,028.05', 13027.05, 'MATCH', 'ข้อมูลถูกต้อง'],
    'PDF over by 1.01' => ['13,028.05', 13027.04, 'MISMATCH', 'ยอดรวมไม่ตรง (PDF: 13,028.05 / ASN: 13,027.04)'],
    'PDF below by 1.00' => ['13,028.05', 13029.05, 'MATCH', 'ข้อมูลถูกต้อง'],
    'PDF below by 1.01' => ['13,028.05', 13029.06, 'MISMATCH', 'ยอดรวมไม่ตรง (PDF: 13,028.05 / ASN: 13,029.06)'],
    'PDF over' => ['13,028.05', 7504.15, 'MISMATCH', 'ยอดรวมไม่ตรง (PDF: 13,028.05 / ASN: 7,504.15)'],
    'PDF short' => ['7,504.15', 13028.05, 'MISMATCH', 'ยอดรวมไม่ตรง (PDF: 7,504.15 / ASN: 13,028.05)'],
    'footer missing' => ['Null', 13028.05, 'MATCH', 'ข้อมูลถูกต้อง'],
    'ASN cost 0' => ['13,028.05', 0.0, 'MATCH', 'ข้อมูลถูกต้อง'],
];
$amountFaults = [];
foreach ($amountCases as $label => [$pdfExcl, $asnCost, $status, $reason]) {
    $line = reconcileOne($jm, 'amount', $asnCost, 1, $pdfExcl);
    if ([$line['status'], $line['reason']] !== [$status, $reason]) {
        $amountFaults[$label] = [$line['status'], $line['reason']];
    }
}
check('Amt Excl VAT of the PDF and Cost Ex VAT of the ASN may differ by ฿1.00 at most, either way; past that the problem names both totals, not the side or the difference', $amountFaults === [], json_encode($amountFaults, JSON_UNESCAPED_UNICODE));

function pdfRow(string $inv, int $page, string $barcode, float $amount, string $excl, string $vat): array
{
    return [
        'tax_invoice' => $inv, 'page_no' => $page, 'barcode' => $barcode, 'units' => 1, 'amount' => $amount,
        'total_amount' => 'Null', 'amt_excl_vat' => $excl, 'vat_amount' => $vat, 'ship_to_party' => '', 'address' => '',
    ];
}

$job = $jm->createJob('Shifted page');
$jm->saveAsnRecords($job['id'], 't.xlsx', [
    ['invoice_no' => '9100001-1', 'ean_upc' => '8850000000001', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 2400.00, 'total_vat' => 168.00],
    ['invoice_no' => '9100001-1', 'ean_upc' => '8850000000002', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 2400.00, 'total_vat' => 168.00],
    ['invoice_no' => '9100001-1', 'ean_upc' => '8850000000003', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 2400.00, 'total_vat' => 168.00],
    ['invoice_no' => '9100001-2', 'ean_upc' => '8850000000004', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1000.00, 'total_vat' => 70.00],
]);
$shifted = [
    pdfRow('9100001-1', 1, '8850000000001', 1070, '2,400.00', '168.00'),
    pdfRow('9100001-1', 1, '8850000000002', 1070, '2,400.00', '168.00'),
    pdfRow('9100001-2', 2, '8850000000003', 1070, '2,400.00', '168.00'),
    pdfRow('9100001-2', 3, '8850000000004', 1070, '1,000.00', '70.00'),
];
$result = (new AsnReconciler(Database::getConnection()))->reconcile($job['id'], $shifted, $job);
$statusByBarcode = [];
foreach ($result['results'] as $x) {
    $statusByBarcode[$x['asn']['ean_upc']] = $x['status'];
}
check(
    'an invoice whose page carries the wrong number is pending for every line, not mismatched',
    ($statusByBarcode['8850000000001'] ?? '') === 'PENDING_FIX' && ($statusByBarcode['8850000000002'] ?? '') === 'PENDING_FIX' && ($statusByBarcode['8850000000003'] ?? '') === 'PENDING_FIX',
    json_encode($statusByBarcode)
);

$job = $jm->createJob('Culprit line');
$jm->saveAsnRecords($job['id'], 't.xlsx', [
    ['invoice_no' => '9300001-1', 'ean_upc' => '8850000000021', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1000.00, 'total_vat' => 70.00],
    ['invoice_no' => '9300001-1', 'ean_upc' => '8850000000022', 'quantity' => 2, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1000.00, 'total_vat' => 70.00],
]);
$culpritItems = [
    pdfRow('9300001-1', 1, '8850000000021', 535, '500.00', '35.00'),
    pdfRow('9300001-1', 1, '8850000000022', 535, '500.00', '35.00'),
];
$culpritResult = (new AsnReconciler(Database::getConnection()))->reconcile($job['id'], $culpritItems, $job);
$reasonByBarcode = [];
foreach ($culpritResult['results'] as $x) {
    $reasonByBarcode[$x['asn']['ean_upc']] = $x['reason'];
}
check(
    'a line that matches on its own names the line that makes its bill fail',
    strpos($reasonByBarcode['8850000000021'] ?? '', '8850000000022') !== false && strpos($reasonByBarcode['8850000000021'] ?? '', 'จำนวนไม่ตรง') !== false,
    $reasonByBarcode['8850000000021'] ?? 'missing'
);
$billProblems = 'ยอดรวมไม่ตรง (PDF: 500.00 / ASN: 1,000.00), VAT ไม่ตรง (PDF: ฿35.00 / ASN: ฿70.00)';
check(
    'several problems of a line are joined by ", " in the order quantity, amount, VAT, and so are the problems inside the parentheses of the sentence of a line that is right itself',
    $reasonByBarcode === [
        '8850000000021' => 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 8850000000022 จำนวนไม่ตรง (PDF: 1 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง (' . $billProblems . ')',
        '8850000000022' => 'จำนวนไม่ตรง (PDF: 1 ชิ้น / ASN: 2 ชิ้น), ' . $billProblems,
    ],
    json_encode($reasonByBarcode, JSON_UNESCAPED_UNICODE)
);

$job = $jm->createJob('Line on another bill');
$jm->saveAsnRecords($job['id'], 't.xlsx', [
    ['invoice_no' => '9600001-1', 'ean_upc' => '8850000000041', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 3000.00, 'total_vat' => 210.00],
    ['invoice_no' => '9600001-1', 'ean_upc' => '8850000000042', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 3000.00, 'total_vat' => 210.00],
    ['invoice_no' => '9600001-2', 'ean_upc' => '8850000000043', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 2000.00, 'total_vat' => 140.00],
    ['invoice_no' => '9600001-2', 'ean_upc' => '8850000000044', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 2000.00, 'total_vat' => 140.00],
]);
$strayResult = (new AsnReconciler(Database::getConnection()))->reconcile($job['id'], [
    pdfRow('9600001-1', 1, '8850000000041', 1070, '3,000.00', '210.00'),
    pdfRow('9600001-1', 1, '8850000000042', 1070, '3,000.00', '210.00'),
    pdfRow('9600001-1', 1, '8850000000043', 1070, '3,000.00', '210.00'),
    pdfRow('9600001-2', 2, '8850000000044', 1070, '1,000.00', '70.00'),
], $job);
$stray = [];
foreach ($strayResult['results'] as $x) {
    $stray[$x['asn']['ean_upc']] = [$x['status'], $x['reason']];
}
check(
    'a line printed on the page of another bill, which no page renumbering can move, still waits for the fix with the number printed on its page, and so does the rest of its bill',
    ($stray['8850000000043'] ?? null) === ['PENDING_FIX', 'เลขที่พิมพ์ในบิล PDF คือ 9600001-1 แต่ใน ASN เป็น 9600001-2'] && ($stray['8850000000044'][0] ?? '') === 'PENDING_FIX',
    json_encode($stray, JSON_UNESCAPED_UNICODE)
);

/**
 * The compare lines of one bill on a page of its own: barcode => [status, reason, action]
 *
 * @param array $asn barcode => pieces in the ASN
 * @param array $pdf [barcode, pieces] rows of the PDF, in print order
 */
function reconcileBill(JobManager $jm, string $label, array $asn, array $pdf, float $asnCost, string $pdfExcl, float $asnVat = 0.0, string $pdfVat = '0.00'): array
{
    $job = $jm->createJob("Bill $label");
    $asnRows = [];
    foreach ($asn as $barcode => $pieces) {
        $asnRows[] = ['invoice_no' => '9700001-1', 'ean_upc' => (string)$barcode, 'quantity' => $pieces, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => $asnCost, 'total_vat' => $asnVat];
    }
    $jm->saveAsnRecords($job['id'], 't.xlsx', $asnRows);
    $items = [];
    foreach ($pdf as [$barcode, $pieces]) {
        $items[] = [
            'tax_invoice' => '9700001-1', 'page_no' => 1, 'barcode' => (string)$barcode, 'units' => $pieces, 'total_amount' => 'Null',
            'amt_excl_vat' => $pdfExcl, 'vat_amount' => $pdfVat, 'ship_to_party' => '', 'address' => '',
        ];
    }
    $lines = [];
    foreach ((new AsnReconciler(Database::getConnection()))->reconcile($job['id'], $items, $job)['results'] as $x) {
        $lines[$x['asn']['ean_upc']] = [$x['status'], $x['reason'], $x['action']];
    }
    return $lines;
}

$pdfOnlyCases = [
    'a barcode of the PDF the ASN does not list, its pieces summed over its rows, makes every line wrong even with the amount within ฿1.00' => [
        reconcileBill($jm, 'one extra', ['8850000000201' => 1, '8850000000202' => 1], [['8850000000201', 1], ['8850000000209', 1], ['8850000000202', 1], ['8850000000209', 1]], 2000.00, '2,000.50'),
        [
            '8850000000201' => ['MISMATCH', 'บาร์โค้ด 8850000000209 มีใน PDF แต่ไม่มีใน ASN (2 ชิ้น)', ''],
            '8850000000202' => ['MISMATCH', 'บาร์โค้ด 8850000000209 มีใน PDF แต่ไม่มีใน ASN (2 ชิ้น)', ''],
        ],
    ],
    'two such barcodes are two problems in the order the PDF prints them, before the amount problem' => [
        reconcileBill($jm, 'two extra', ['8850000000211' => 1], [['8850000000211', 1], ['8850000000219', 1], ['8850000000218', 2]], 1000.00, '5,000.00'),
        ['8850000000211' => ['MISMATCH', 'บาร์โค้ด 8850000000219 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), บาร์โค้ด 8850000000218 มีใน PDF แต่ไม่มีใน ASN (2 ชิ้น), '
            . 'ยอดรวมไม่ตรง (PDF: 5,000.00 / ASN: 1,000.00)', '']],
    ],
    'four such barcodes show the first three and the count of the rest' => [
        reconcileBill($jm, 'four extra', ['8850000000221' => 1], [['8850000000221', 1], ['8850000000226', 1], ['8850000000227', 1], ['8850000000228', 1], ['8850000000229', 1]], 1000.00, '1,000.00'),
        ['8850000000221' => ['MISMATCH', 'บาร์โค้ด 8850000000226 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), บาร์โค้ด 8850000000227 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), บาร์โค้ด 8850000000228 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น) และอีก 1 รายการ', '']],
    ],
    'the problems read quantity, barcode only in the PDF, amount, VAT, and so do the parentheses of the sentence of the line that is right itself' => [
        reconcileBill($jm, 'order', ['8850000000231' => 2, '8850000000232' => 1], [['8850000000231', 1], ['8850000000232', 1], ['8850000000239', 1]], 3000.00, '2,500.00', 210.00, '175.00'),
        [
            '8850000000231' => ['MISMATCH', 'จำนวนไม่ตรง (PDF: 1 ชิ้น / ASN: 2 ชิ้น), บาร์โค้ด 8850000000239 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), '
                . 'ยอดรวมไม่ตรง (PDF: 2,500.00 / ASN: 3,000.00), VAT ไม่ตรง (PDF: ฿175.00 / ASN: ฿210.00)', ''],
            '8850000000232' => ['MISMATCH', 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 8850000000231 จำนวนไม่ตรง (PDF: 1 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง (บาร์โค้ด 8850000000239 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), '
                . 'ยอดรวมไม่ตรง (PDF: 2,500.00 / ASN: 3,000.00), VAT ไม่ตรง (PDF: ฿175.00 / ASN: ฿210.00))', ''],
        ],
    ],
    'a barcode billed in place of pieces of another, the amount within ฿1.00, makes the right line name the line with the wrong pieces and, in the parentheses, the barcode only in the PDF' => [
        reconcileBill($jm, 'swapped', ['8850000000241' => 2, '8850000000242' => 1], [['8850000000241', 1], ['8850000000242', 1], ['8850000000249', 1]], 3000.00, '3,000.00'),
        [
            '8850000000241' => ['MISMATCH', 'จำนวนไม่ตรง (PDF: 1 ชิ้น / ASN: 2 ชิ้น), บาร์โค้ด 8850000000249 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น)', ''],
            '8850000000242' => ['MISMATCH', 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 8850000000241 จำนวนไม่ตรง (PDF: 1 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง (บาร์โค้ด 8850000000249 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น))', ''],
        ],
    ],
    'a barcode billed instead of the one the ASN lists makes the missing line say so and name the barcode only in the PDF, and the right line names both' => [
        reconcileBill($jm, 'other barcode', ['8850000000251' => 1, '8850000000252' => 1], [['8850000000251', 1], ['8850000000259', 1]], 2000.00, '2,000.00'),
        [
            '8850000000251' => ['MISMATCH', 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 8850000000252 ไม่พบใน PDF จึงทำให้ยอดรวมบิลไม่ตรง (บาร์โค้ด 8850000000259 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น))', ''],
            '8850000000252' => ['MISMATCH', 'มีรายการในไฟล์ ASN แต่ไม่พบไฟล์ PDF ของบิลนี้ [PDF ไม่ออก หรือบิลตกหล่น], บาร์โค้ด 8850000000259 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น)', ''],
        ],
    ],
    'a bill whose PDF lists only its ASN barcodes is unchanged: complete' => [
        reconcileBill($jm, 'no extra', ['8850000000261' => 1, '8850000000262' => 1], [['8850000000261', 1], ['8850000000262', 1]], 2000.00, '2,000.00'),
        ['8850000000261' => ['MATCH', 'ข้อมูลถูกต้อง', 'ข้อมูลถูกต้อง'], '8850000000262' => ['MATCH', 'ข้อมูลถูกต้อง', 'ข้อมูลถูกต้อง']],
    ],
];
foreach ($pdfOnlyCases as $name => [$got, $want]) {
    check($name, $got === $want, json_encode($got, JSON_UNESCAPED_UNICODE));
}

/**
 * The compare lines of bills printed in PDF files, a page each in the order given, one piece per line: invoice =>
 * the distinct "status | reason | action" its lines read
 *
 * @param array $bills invoice => ['file' => PDF name, 'lines' => line amounts, 'footer' => [Promotion Discount, Total Discount
 *                     percentage, Amt Excl VAT] as printed, 'cost' => ASN Total Cost Ex VAT, the printed Amt Excl VAT when left out,
 *                     'pages' => how many pages the lines run over, the footer on the last]
 */
function reconcileFooters(JobManager $jm, string $label, array $bills): array
{
    $job = $jm->createJob("Footer $label");
    $asn = [];
    $items = [];
    $pageOf = [];
    $n = 0;
    foreach ($bills as $invoice => $bill) {
        [$promo, $percent, $excl] = $bill['footer'];
        $pages = $bill['pages'] ?? 1;
        foreach ($bill['lines'] as $i => $amount) {
            $barcode = sprintf('88500009%05d', ++$n);
            $page = ($pageOf[$bill['file']] ?? 0) + 1 + intdiv($i * $pages, count($bill['lines']));
            $last = $page === ($pageOf[$bill['file']] ?? 0) + $pages;
            $items[] = [
                'filename' => $bill['file'], 'tax_invoice' => $invoice, 'page_no' => $page, 'barcode' => $barcode, 'units' => 1, 'amount' => number_format($amount, 2),
                'promotion_discount' => $last ? $promo : 'Null', 'discount_percent' => $last ? $percent : 'Null', 'amt_excl_vat' => $last ? $excl : 'Null',
                'vat_amount' => $last ? '0.00' : 'Null', 'ship_to_party' => '', 'address' => '',
            ];
            $asn[] = [
                'invoice_no' => $invoice, 'ean_upc' => $barcode, 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1,
                'total_cost_ex_vat' => $bill['cost'] ?? (float)str_replace(',', '', $excl), 'total_vat' => 0,
            ];
        }
        $pageOf[$bill['file']] = ($pageOf[$bill['file']] ?? 0) + $pages;
    }
    $jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
    $lines = [];
    foreach ((new AsnReconciler(Database::getConnection()))->reconcile($job['id'], $items, $job)['results'] as $x) {
        $lines[$x['asn']['invoice_no']][$x['status'] . ' | ' . $x['reason'] . ' | ' . $x['action']] = true;
    }
    return array_map('array_keys', $lines);
}

// Footers worked out by hand from (line amounts - Promotion Discount) x (1 - percentage) / 1.07: bill A has lines of
// 10,000 and 5,000 and its footer FA (1,000 off, 28%) prints 9,420.56; B's line of 20,000 with FB (2,000 off, 20%)
// 13,457.94; C's line of 8,000 with FC (no promotion, 24%) 5,682.24
$fa = ['1,000.00', '28%', '9,420.56'];
$fb = ['2,000.00', '20%', '13,457.94'];
$fc = ['0.00', '24%', '5,682.24'];
$own = function (string $printed, string $fromLines): string {
    return "(ยอดรวมก่อน VAT ท้ายบิล: $printed / จากรายการของบิลนี้: $fromLines)";
};
$footerCases = [
    'footers that the lines of their own bill give are no problem, on one page or over two; the two bills of one file only wait for their split' => [
        reconcileFooters($jm, 'own', [
            'FA-1' => ['file' => 'ab.pdf', 'lines' => [10000, 5000], 'footer' => $fa, 'pages' => 2],
            'FB-1' => ['file' => 'ab.pdf', 'lines' => [20000], 'footer' => $fb],
            'FC-1' => ['file' => 'c.pdf', 'lines' => [8000], 'footer' => $fc],
        ]),
        [
            'FA-1' => ['PENDING_FIX | ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ 1-2 | แยกหน้าที่ 1 ถึง 2 ออกมาเป็นอีกไฟล์'],
            'FB-1' => ['PENDING_FIX | ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ 3 | แยกหน้าที่ 3 ออกมาเป็นอีกไฟล์'],
            'FC-1' => ['MATCH | ข้อมูลถูกต้อง | ข้อมูลถูกต้อง'],
        ],
    ],
    'two bills of one file with their footers exchanged each name the other, and with the ASN cost of their own lines the amount problem follows' => [
        reconcileFooters($jm, 'pair', [
            'FA-1' => ['file' => 'ab.pdf', 'lines' => [10000, 5000], 'footer' => $fb, 'cost' => 9420.56],
            'FB-1' => ['file' => 'ab.pdf', 'lines' => [20000], 'footer' => $fa, 'cost' => 13457.94],
        ]),
        [
            'FA-1' => ['MISMATCH | ยอดรวมท้ายบิลเป็นของบิล FB-1 ' . $own('13,457.94', '9,719.63') . ', ยอดรวมไม่ตรง (PDF: 13,457.94 / ASN: 9,420.56) | '],
            'FB-1' => ['MISMATCH | ยอดรวมท้ายบิลเป็นของบิล FA-1 ' . $own('9,420.56', '12,785.05') . ', ยอดรวมไม่ตรง (PDF: 9,420.56 / ASN: 13,457.94) | '],
        ],
    ],
    'three bills whose footers went round name each the bill whose lines give the footer it prints, even with the ASN cost equal to that footer' => [
        reconcileFooters($jm, 'cycle', [
            'FA-1' => ['file' => 'abc.pdf', 'lines' => [10000, 5000], 'footer' => $fb],
            'FB-1' => ['file' => 'abc.pdf', 'lines' => [20000], 'footer' => $fc],
            'FC-1' => ['file' => 'abc.pdf', 'lines' => [8000], 'footer' => $fa],
        ]),
        [
            'FA-1' => ['MISMATCH | ยอดรวมท้ายบิลเป็นของบิล FB-1 ' . $own('13,457.94', '9,719.63') . ' | '],
            'FB-1' => ['MISMATCH | ยอดรวมท้ายบิลเป็นของบิล FC-1 ' . $own('5,682.24', '14,205.61') . ' | '],
            'FC-1' => ['MISMATCH | ยอดรวมท้ายบิลเป็นของบิล FA-1 ' . $own('9,420.56', '4,710.28') . ' | '],
        ],
    ],
    'a footer that no bill of the file gives says it does not match the bill\'s lines; a bill of another file whose lines give it is never named' => [
        reconcileFooters($jm, 'none', [
            'FA-1' => ['file' => 'a.pdf', 'lines' => [10000, 5000], 'footer' => ['1,000.00', '28%', '13,457.94']],
            'FB-1' => ['file' => 'a.pdf', 'lines' => [8000], 'footer' => $fc],
            'FC-1' => ['file' => 'b.pdf', 'lines' => [20000, 1000], 'footer' => ['1,000.00', '28%', '13,457.94']],
        ]),
        [
            'FA-1' => ['MISMATCH | ยอดรวมท้ายบิลไม่ตรงกับรายการของบิลนี้ ' . $own('13,457.94', '9,420.56') . ' | '],
            'FB-1' => ['PENDING_FIX | ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ 2 | แยกหน้าที่ 2 ออกมาเป็นอีกไฟล์'],
            'FC-1' => ['MATCH | ข้อมูลถูกต้อง | ข้อมูลถูกต้อง'],
        ],
    ],
    'a footer without its Promotion Discount or its percentage is not judged, however far its total is' => [
        reconcileFooters($jm, 'unread', [
            'FA-1' => ['file' => 'a.pdf', 'lines' => [10000, 5000], 'footer' => ['-', '28%', '50,000.00']],
            'FB-1' => ['file' => 'b.pdf', 'lines' => [20000], 'footer' => ['2,000.00', 'Null', '50,000.00']],
        ]),
        ['FA-1' => ['MATCH | ข้อมูลถูกต้อง | ข้อมูลถูกต้อง'], 'FB-1' => ['MATCH | ข้อมูลถูกต้อง | ข้อมูลถูกต้อง']],
    ],
    'the printed total may be ฿1.00 away from what the lines give, either way, ฿1.01 is too far (1,070.00 of lines, no discount, give 1,000.00)' => [
        reconcileFooters($jm, 'tolerance', [
            'T1-1' => ['file' => 't1.pdf', 'lines' => [1070], 'footer' => ['0.00', '0%', '1,001.00']],
            'T2-1' => ['file' => 't2.pdf', 'lines' => [1070], 'footer' => ['0.00', '0%', '1,001.01']],
            'T3-1' => ['file' => 't3.pdf', 'lines' => [1070], 'footer' => ['0.00', '0%', '999.00']],
            'T4-1' => ['file' => 't4.pdf', 'lines' => [1070], 'footer' => ['0.00', '0%', '998.99']],
        ]),
        [
            'T1-1' => ['MATCH | ข้อมูลถูกต้อง | ข้อมูลถูกต้อง'],
            'T2-1' => ['MISMATCH | ยอดรวมท้ายบิลไม่ตรงกับรายการของบิลนี้ ' . $own('1,001.01', '1,000.00') . ' | '],
            'T3-1' => ['MATCH | ข้อมูลถูกต้อง | ข้อมูลถูกต้อง'],
            'T4-1' => ['MISMATCH | ยอดรวมท้ายบิลไม่ตรงกับรายการของบิลนี้ ' . $own('998.99', '1,000.00') . ' | '],
        ],
    ],
];
foreach ($footerCases as $name => [$got, $want]) {
    check($name, $got === $want, json_encode($got, JSON_UNESCAPED_UNICODE));
}
$job = $jm->createJob('Footer order');
$jm->saveAsnRecords($job['id'], 't.xlsx', [
    ['invoice_no' => 'FO-1', 'ean_upc' => '8850000098001', 'quantity' => 2, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 9000.00, 'total_vat' => 630.00],
    ['invoice_no' => 'FO-1', 'ean_upc' => '8850000098002', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 9000.00, 'total_vat' => 630.00],
    ['invoice_no' => 'FO-2', 'ean_upc' => '8850000098003', 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 13457.94, 'total_vat' => 942.06],
]);
$orderRow = function (string $invoice, int $page, string $barcode, float $amount, array $footer, string $vat): array {
    return [
        'filename' => 'fo.pdf', 'tax_invoice' => $invoice, 'page_no' => $page, 'barcode' => $barcode, 'units' => 1, 'amount' => $amount,
        'promotion_discount' => $footer[0], 'discount_percent' => $footer[1], 'amt_excl_vat' => $footer[2], 'vat_amount' => $vat, 'ship_to_party' => '', 'address' => '',
    ];
};
$orderLines = [];
foreach ((new AsnReconciler(Database::getConnection()))->reconcile($job['id'], [
    $orderRow('FO-1', 1, '8850000098001', 10000, $fb, '942.06'),
    $orderRow('FO-1', 1, '8850000098002', 4000, $fb, '942.06'),
    $orderRow('FO-1', 1, '8850000098009', 1000, $fb, '942.06'),
    $orderRow('FO-2', 2, '8850000098003', 20000, $fa, '659.44'),
], $job)['results'] as $x) {
    $orderLines[$x['asn']['ean_upc']] = [$x['status'], $x['reason'], $x['action']];
}
$orderProblems = 'บาร์โค้ด 8850000098009 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), ยอดรวมท้ายบิลเป็นของบิล FO-2 ' . $own('13,457.94', '9,719.63')
    . ', ยอดรวมไม่ตรง (PDF: 13,457.94 / ASN: 9,000.00), VAT ไม่ตรง (PDF: ฿942.06 / ASN: ฿630.00)';
check(
    'the problems of a bill read quantity, barcode only in the PDF, footer of another bill, amount, VAT, and so do the parentheses of the sentence of its right line',
    $orderLines === [
        '8850000098001' => ['MISMATCH', 'จำนวนไม่ตรง (PDF: 1 ชิ้น / ASN: 2 ชิ้น), ' . $orderProblems, ''],
        '8850000098002' => ['MISMATCH', 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 8850000098001 จำนวนไม่ตรง (PDF: 1 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง (' . $orderProblems . ')', ''],
        '8850000098003' => ['MISMATCH', 'ยอดรวมท้ายบิลเป็นของบิล FO-1 ' . $own('9,420.56', '12,785.05') . ', ยอดรวมไม่ตรง (PDF: 9,420.56 / ASN: 13,457.94), VAT ไม่ตรง (PDF: ฿659.44 / ASN: ฿942.06)', ''],
    ],
    json_encode($orderLines, JSON_UNESCAPED_UNICODE)
);

$job = $jm->createJob('Duplicate bills');
$upload = $jm->getJobUploadDir($job);
$finished = $jm->getJobFinishedDir($job);
copy($sample, $upload . '/first.pdf');
api('extract_job_batch', ['job_id' => $job['id'], 'files' => json_encode([['filename' => 'first.pdf', 'rel_path' => 'first.pdf']])]);
copy($sample, $upload . '/second.pdf');
$scan = $jm->scanSourceFiles($job['id']);
check(
    'scan flags a file that carries a bill already in the job under another name',
    $scan['new_count'] === 0 && count($scan['duplicate_files'] ?? []) === 1 && ($scan['duplicate_files'][0]['duplicate_of'] ?? '') === 'first.pdf',
    json_encode($scan['duplicate_files'] ?? null)
);
$skipped = api('extract_job_batch', ['job_id' => $job['id'], 'files' => json_encode([['filename' => 'second.pdf', 'rel_path' => 'second.pdf']])]);
$rowCount = (int)$pdo->query('SELECT COUNT(*) FROM job_items WHERE job_id = ' . $job['id'])->fetchColumn();
check(
    'a duplicate bill is left alone when skipped',
    ($skipped['processed_files'] ?? -1) === 0 && $rowCount === 30 && is_file($upload . '/second.pdf'),
    'processed=' . ($skipped['processed_files'] ?? '?') . ' rows=' . $rowCount
);
api('extract_job_batch', ['job_id' => $job['id'], 'files' => json_encode([['filename' => 'second.pdf', 'rel_path' => 'second.pdf']]), 'replace_duplicates' => 1]);
$names = $pdo->query('SELECT DISTINCT filename FROM job_items WHERE job_id = ' . $job['id'])->fetchAll(PDO::FETCH_COLUMN);
$rowCount = (int)$pdo->query('SELECT COUNT(*) FROM job_items WHERE job_id = ' . $job['id'])->fetchColumn();
check('replacing swaps the earlier file for the new one without doubling rows', $names === ['second.pdf'] && $rowCount === 30, json_encode($names) . ' rows=' . $rowCount);

$job = $jm->createJob('Same ASN file');
$sameRows = [
    ['invoice_no' => '9400001-1', 'ean_upc' => '8850000000031', 'quantity' => 1, 'dest_store_no' => '10101', 'selling_price' => 10.5, 'total_cost_ex_vat' => 100.0, 'total_vat' => 7.0, 'delivery_no' => 'D1'],
    ['invoice_no' => '9400001-1', 'ean_upc' => '8850000000032', 'quantity' => 2, 'dest_store_no' => '10101', 'selling_price' => 20, 'total_cost_ex_vat' => 100.0, 'total_vat' => 7.0, 'delivery_no' => 'D1'],
];
$jm->saveAsnRecords($job['id'], 'same.xlsx [s]', $sameRows);
$pdo->exec('UPDATE jobs SET asn_reconciled = 1 WHERE id = ' . $job['id']);
$stored = $jm->describeStoredAsnFile($job['id'], 'same.xlsx [s]', $sameRows);
check('an ASN file that was imported before is recognised, with identical content', $stored['stored'] === 2 && $stored['incoming'] === 2 && $stored['identical'] === true, json_encode($stored));
$changedRows = $sameRows;
$changedRows[1]['quantity'] = 3;
check('a changed re-import is told apart from an identical one', $jm->describeStoredAsnFile($job['id'], 'same.xlsx [s]', $changedRows)['identical'] === false);
check('an ASN file that was never imported has nothing stored', $jm->describeStoredAsnFile($job['id'], 'other.xlsx [s]', $sameRows)['stored'] === 0);
$skippedSame = $jm->saveAsnRecords($job['id'], 'same.xlsx [s]', $changedRows, true, 'skip', 'skip');
$qtyAfterSkip = (int)$pdo->query("SELECT quantity FROM job_asn_records WHERE job_id = {$job['id']} AND ean_upc = '8850000000032'")->fetchColumn();
check(
    'skipping a file imported before changes nothing and keeps the comparison',
    $skippedSame === 0 && $qtyAfterSkip === 2 && (int)$pdo->query('SELECT asn_reconciled FROM jobs WHERE id = ' . $job['id'])->fetchColumn() === 1 && !empty($jm->lastAsnImport['skipped_same_file'])
);
$jm->saveAsnRecords($job['id'], 'same.xlsx [s]', $changedRows, true, 'skip', 'replace');
$qtyAfterReplace = (int)$pdo->query("SELECT quantity FROM job_asn_records WHERE job_id = {$job['id']} AND ean_upc = '8850000000032'")->fetchColumn();
check(
    'overwriting a file imported before replaces its rows and asks for a new comparison',
    $qtyAfterReplace === 3 && (int)$pdo->query('SELECT asn_reconciled FROM jobs WHERE id = ' . $job['id'])->fetchColumn() === 0
);

$job = $jm->createJob('Duplicate ASN');
$asnRow = function (string $inv, string $ean, int $qty) {
    return ['invoice_no' => $inv, 'ean_upc' => $ean, 'quantity' => $qty, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1];
};
$jm->saveAsnRecords($job['id'], 'a.xlsx', [$asnRow('9200001-1', '8850000000011', 1), $asnRow('9200001-2', '8850000000012', 1)]);
check('overlap lists the invoices already held by another ASN file', $jm->findAsnOverlap($job['id'], 'd.xlsx', ['9200001-1', '9200009-1']) === ['9200001-1' => ['a.xlsx']]);
$imported = $jm->saveAsnRecords($job['id'], 'b.xlsx', [$asnRow('9200001-1', '8850000000011', 1), $asnRow('9200002-1', '8850000000013', 1)]);
$total = (int)$pdo->query('SELECT COUNT(*) FROM job_asn_records WHERE job_id = ' . $job['id'])->fetchColumn();
check('a second ASN file skips invoices another file already holds', $imported === 1 && $total === 3, "imported=$imported total=$total");
$jm->saveAsnRecords($job['id'], 'c.xlsx', [$asnRow('9200001-2', '8850000000012', 5)], true, 'replace');
$rows = $pdo->query("SELECT source_file, quantity FROM job_asn_records WHERE job_id = {$job['id']} AND invoice_no = '9200001-2'")->fetchAll(PDO::FETCH_ASSOC);
check('replacing swaps the earlier rows of that invoice for the new file', count($rows) === 1 && $rows[0]['source_file'] === 'c.xlsx' && (int)$rows[0]['quantity'] === 5, json_encode($rows));

$job = $jm->createJob('Print flow');
check('a new job gets a print folder', is_dir($jm->getJobPrintDir($job, false)));
$finished = $jm->getJobFinishedDir($job);
copy($sample, $finished . '/multi.pdf');
$rows = (new PdfExtractor())->extractDetailedRows($finished . '/multi.pdf');
$entries = [['filename' => 'multi.pdf', 'storedPath' => $finished . '/multi.pdf', 'items' => $rows]];
$jm->recordExtractedBatch($job['id'], $entries, true);
$asn = [];
foreach ($rows as $r) {
    $page = (int)$r['page_no'];
    $asn[] = [
        'invoice_no' => $page <= 2 ? '93247359-1' : ($page === 3 ? '93247359-2' : '93247359-3'),
        'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1,
    ];
}
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
(new PdfFixer($pdo))->fixJob($job['id']);
$footers = [];
foreach ($jm->getJobItems($job['id']) as $it) {
    if (!in_array($it['amt_excl_vat'], ['Null', '-', ''], true)) {
        $footers[$it['tax_invoice']] = [(float)str_replace(',', '', $it['amt_excl_vat']), (float)str_replace(',', '', $it['vat_amount'])];
    }
}
foreach ($footers as $inv => $f) {
    $pdo->exec("UPDATE job_asn_records SET total_cost_ex_vat = {$f[0]}, total_vat = {$f[1]} WHERE job_id = {$job['id']} AND invoice_no = '$inv'");
}
$printer = new PrintManager($jm);
$plan = $printer->plan($job['id']);
check(
    'the first round holds the three complete invoices under the store number of their Ship to Party when the ASN has none',
    $plan['round'] === 1 && $plan['ready'] === ['93247359-1' => '10166', '93247359-2' => '10166', '93247359-3' => '10166'] && $plan['store_count'] === 1,
    json_encode($plan)
);
$summary = $printer->runAll($job['id']);
$printDir = $jm->getJobPrintDir($job, false);
$roundDir = $printDir . '/print ครั้งที่ 1/10166';
$names = is_dir($roundDir) ? array_values(array_diff(scandir($roundDir), ['.', '..'])) : [];
check('files are numbered from 1 without padding and sorted by invoice', $names === ['1_93247359-1.pdf', '2_93247359-2.pdf', '3_93247359-3.pdf'], json_encode($names));
$pageCounts = [];
foreach ($names as $n) {
    $pageCounts[] = count((new \Smalot\PdfParser\Parser())->parseFile($roundDir . '/' . $n)->getPages());
}
check('each printed file holds only the pages of its invoice', $pageCounts === [2, 1, 2], json_encode($pageCounts));
check('no temporary folder is left behind', count(glob($printDir . '/.tmp_*')) === 0 && ($summary['created'] ?? 0) === 3 && ($summary['stores'] ?? 0) === 1);
check('the originals in finished are untouched', is_file($finished . '/multi.pdf') && is_file($finished . '/93247359-1.pdf'));
$again = $printer->plan($job['id']);
check('printed invoices are not offered again', count($again['ready']) === 0 && $again['skipped']['printed'] === 3 && $printer->printedInvoices($job['id'])['93247359-2'] === 1);
$rowsOut = $jm->reconcileJob($job['id'])['results'];
check('the compare result tells which round printed each line', count(array_filter($rowsOut, function ($x) {
    return ($x['printed_round'] ?? null) === 1;
})) === count($rowsOut), count($rowsOut) . ' rows');
$jm->deleteDirectoryRecursive($printDir . '/print ครั้งที่ 1');
check('deleting a round folder makes its invoices printable again', count($printer->plan($job['id'])['ready']) === 3);
mkdir($printDir . '/print ครั้งที่ 4');
check('the next round number follows the highest existing round', $printer->plan($job['id'])['round'] === 5);
rmdir($printDir . '/print ครั้งที่ 4');
$pdo->exec("UPDATE job_asn_records SET dest_store_no = '10200' WHERE job_id = {$job['id']} AND invoice_no = '93247359-2'");
$pdo->exec("UPDATE job_items SET ship_to_party = '', address = '' WHERE job_id = {$job['id']} AND tax_invoice IN ('93247359-2', '93247359-3')");
check(
    'the store folder is the ASN Dest Store No., else the store number on the PDF, else the no-store folder',
    $printer->plan($job['id'])['ready'] === ['93247359-1' => '10166', '93247359-2' => '10200', '93247359-3' => 'ไม่ระบุสาขา'],
    json_encode($printer->plan($job['id'])['ready'], JSON_UNESCAPED_UNICODE)
);

foreach (glob($finished . '/93247359-*.pdf') as $f) {
    unlink($f);
}
$printer->runAll($job['id']);
$rebuilt = $printDir . '/print ครั้งที่ 1/10166/1_93247359-1.pdf';
check(
    'an invoice without a split file is cut from the original when printing',
    is_file($rebuilt) && count((new \Smalot\PdfParser\Parser())->parseFile($rebuilt)->getPages()) === 2
);
$jm->deleteDirectoryRecursive($printDir . '/print ครั้งที่ 1');

foreach (glob($finished . '/*.pdf') as $f) {
    unlink($f);
}
$lost = $printer->runAll($job['id']);
check(
    'when no source file exists nothing is created and every invoice is reported',
    ($lost['created'] ?? -1) === 0 && count($lost['failed'] ?? []) === 3 && count(glob($printDir . '/print ครั้งที่ *')) === 0 && count(glob($printDir . '/.tmp_*')) === 0,
    json_encode($lost)
);

$job = $jm->createJob('Keeps print');
$kept = $jm->getJobPrintDir($job) . '/print ครั้งที่ 1/00001';
mkdir($kept, 0777, true);
file_put_contents($kept . '/01_9000001-1.pdf', 'x');
check('a file of an older round with a zero padded name still counts as printed', (new PrintManager($jm))->printedInvoices($job['id']) === ['9000001-1' => 1]);
$jm->clearJobData($job['id']);
$listed = array_column($jm->listJobs(), 'print_file_count', 'name');
check('clearing data keeps the print folder, the job and its file count', is_file($kept . '/01_9000001-1.pdf') && ($listed['Keeps print'] ?? -1) === 1, json_encode($listed));

$thaiPath = 'C:/jobs/ทดสอบ งาน/print';
$command = FileManager::explorerCommand($thaiPath);
preg_match("/FromBase64String\('([A-Za-z0-9+\/=]+)'\)/", $command, $m);
check(
    'the Explorer command is plain ASCII and carries the exact Thai folder path',
    !preg_match('/[^\x20-\x7e]/', $command) && isset($m[1]) && base64_decode($m[1]) === 'C:\jobs\ทดสอบ งาน\print',
    $command
);
$notLocal = api('open_folder', ['job_id' => 1, 'target' => 'job']);
check('opening a folder is refused for requests that do not come from this computer', ($notLocal['status'] ?? '') === 'error', json_encode($notLocal, JSON_UNESCAPED_UNICODE));

$job = $jm->createJob('Gone');
$jm->deleteDirectoryRecursive($jm->getJobFolderDir($job, false));
$names = array_column($jm->listJobs(), 'name');
check('a job whose folder was deleted is not listed', !in_array('Gone', $names, true), json_encode($names));
$recreated = $jm->createJob('Gone');
$gone = array_filter($jm->listJobs(), function ($j) {
    return $j['name'] === 'Gone';
});
check('a new job with the name of a deleted folder starts alone and clean', count($gone) === 1 && (int)reset($gone)['id'] === (int)$recreated['id']);

$job = $jm->createJob('Delete Me');
$finished = $jm->getJobFinishedDir($job);
$deleteEntries = [];
foreach (['one.pdf', 'two.pdf'] as $name) {
    copy($sample, $finished . '/' . $name);
    $deleteEntries[] = ['filename' => $name, 'storedPath' => $finished . '/' . $name, 'items' => (new PdfExtractor())->extractDetailedRows($finished . '/' . $name)];
}
$deleteRows = count($deleteEntries[0]['items']) + count($deleteEntries[1]['items']);
$jm->recordExtractedBatch($job['id'], $deleteEntries, true);
copy($sample, $jm->getJobUploadDir($job) . '/waiting.pdf');
$deleteAsn = function (string $inv, string $ean) {
    return ['invoice_no' => $inv, 'ean_upc' => $ean, 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1, 'total_vat' => 0];
};
$jm->saveAsnRecords($job['id'], 'a.xlsx [s]', [$deleteAsn('9700001-1', '8850000000301'), $deleteAsn('9700001-1', '8850000000302')]);
$jm->saveAsnRecords($job['id'], 'b.xlsx [s]', [$deleteAsn('9700002-1', '8850000000303'), $deleteAsn('9700003-1', '8850000000304')]);
$pdo->exec("UPDATE job_asn_records SET source_file = '' WHERE job_id = {$job['id']} AND invoice_no = '9700003-1'");
$pdo->exec("INSERT INTO job_invoice_fixes (job_id, invoice_no) VALUES ({$job['id']}, '9700001-1'), ({$job['id']}, '9700002-1')");
$printDir = $jm->getJobPrintDir($job);
foreach (['print ครั้งที่ 1/10166/1_9700001-1.pdf', 'print ครั้งที่ 2/10166/1_9700002-1.pdf', 'print ครั้งที่ 2/10200/1_9700003-1.pdf'] as $rel) {
    @mkdir(dirname($printDir . '/' . $rel), 0777, true);
    file_put_contents($printDir . '/' . $rel, '%PDF');
}
$summary = api('job_delete_summary', [], ['job_id' => $job['id']]);
$expectedSummary = [
    'name' => 'Delete Me', 'pdf_files' => 2, 'pdf_rows' => $deleteRows, 'upload_files' => 1, 'asn_files' => 3, 'asn_rows' => 4,
    'print_files' => count(glob($printDir . '/print ครั้งที่ */*/*.pdf')), 'print_rounds' => count(glob($printDir . '/print ครั้งที่ *', GLOB_ONLYDIR)), 'fixed_bills' => 2,
    'cn_files' => 0, 'cn_documents' => 0, 'cn_asn_rows' => 0, 'vbrp_rows' => 0,
];
check(
    'the delete summary counts what the Job holds: PDF files and rows, files waiting in upload here, ASN files (rows without a file name are one) and rows, printed files and rounds, fixed bills',
    ($summary['status'] ?? '') === 'success' && ($summary['data'] ?? null) === $expectedSummary && $expectedSummary['print_files'] === 3 && $expectedSummary['print_rounds'] === 2,
    json_encode([$summary['data'] ?? $summary, $expectedSummary], JSON_UNESCAPED_UNICODE)
);
preg_match('/<div class="d-flex align-items-center gap-3" id="job-status-counts">(.*?)<\/div>\s*<a /s', renderWorkspace(['job_id' => $job['id']]), $statusBar);
$statusText = trim(preg_replace('/\s+/u', ' ', strip_tags($statusBar[1] ?? '')));
$expectedStatus = 'PDF: 2 ไฟล์ | รายการ: ' . number_format($deleteRows) . ' รายการ | ASN: 3 ไฟล์ | รายการ: 4 รายการ | VBRP: 0 แถว';
check('the status bar of the Job page is rendered with its PDF files and rows, its ASN files and rows and its VBRP rows, no total amount', $statusText === $expectedStatus, $statusText);
check(
    'the ASN rows of the status bar explain on hover that duplicate barcodes of one bill are merged in the table, and carry the row count for the page script',
    strpos($statusBar[1] ?? '', 'id="footer-asn-rows-item" data-rows="4" title="4 แถวที่นำเข้า บาร์โค้ดซ้ำในบิลเดียวกันถูกรวมเป็นรายการเดียวในตารางหลังเทียบกับ PDF"') !== false,
    $statusBar[1] ?? ''
);
$stillThere = function () use ($jm, $job) {
    return $jm->getJob($job['id']) !== null && is_dir($jm->getJobFolderDir($job, false));
};
$refusals = [];
foreach (['no name' => [], 'empty name' => ['confirm_name' => ''], 'other case' => ['confirm_name' => 'delete me'], 'other job' => ['confirm_name' => 'Gone']] as $label => $extra) {
    $res = api('delete_job', ['job_id' => $job['id']] + $extra);
    if (($res['status'] ?? '') !== 'error' || !preg_match('/[\x{0E00}-\x{0E7F}]/u', $res['message'] ?? '') || !$stillThere()) {
        $refusals[$label] = $res;
    }
}
check('deleting a Job without its exact name, or with another name, deletes nothing and answers a Thai error', $refusals === [], json_encode($refusals, JSON_UNESCAPED_UNICODE));
$res = api('delete_job', ['job_id' => $job['id'], 'confirm_name' => '  Delete Me ']);
check('deleting with the exact Job name, spaces around it ignored, removes the Job and its folder', ($res['status'] ?? '') === 'success' && !$stillThere(), json_encode($res, JSON_UNESCAPED_UNICODE));
$indexPage = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --index');
check(
    'the Job list page renders the delete modal with the field for the Job name and its button disabled with the countdown',
    strpos($indexPage, 'id="deleteJobConfirmInput"') !== false && preg_match('/id="btnConfirmDeleteJob" disabled>/', $indexPage) === 1
        && strpos($indexPage, 'ลบ Job (3)') !== false && strpos($indexPage, 'id="deleteJobPrintWarning"') !== false
);

$job = $jm->createJob('Overlay');
$finished = $jm->getJobFinishedDir($job);
copy($sample, $finished . '/multi.pdf');
$rows = (new PdfExtractor())->extractDetailedRows($finished . '/multi.pdf');
$entries = [['filename' => 'multi.pdf', 'storedPath' => $finished . '/multi.pdf', 'items' => $rows]];
$jm->recordExtractedBatch($job['id'], $entries, true);
$asn = [];
foreach ($rows as $r) {
    $page = (int)$r['page_no'];
    $asn[] = [
        'invoice_no' => $page <= 2 ? '93247359-1' : ($page === 3 ? '93247359-2' : '93247359-3'),
        'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1,
    ];
}
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
$linesBefore = $jm->reconcileJob($job['id'])['results'];
$wrongLines = array_filter($linesBefore, function ($r) {
    return $r['status'] !== 'MISMATCH' || $r['can_fix'] || $r['action'] !== '' || preg_match(FORBIDDEN_REMARK_WORDS, $r['reason'])
        || preg_match('/^ยอดรวมไม่ตรง \(PDF: ([\d,.]+) \/ ASN: 1\.00\)$/u', $r['reason'], $m) !== 1 || (float)str_replace(',', '', $m[1]) <= 1.0 || pagesNamed($r['reason']) !== [];
});
check(
    'bills whose PDF total is over the ASN cost are wrong before the fix on every line, mislabelled pages or not: the problem is the amount with no page in it, and there is no solution',
    count(array_unique(array_map(function ($r) {
        return $r['asn']['invoice_no'];
    }, $linesBefore))) === 3 && $wrongLines === [],
    json_encode(array_slice(array_values($wrongLines), 0, 2), JSON_UNESCAPED_UNICODE)
);
(new PdfFixer(Database::getConnection()))->fixJob($job['id']);
$faults = beforeFixFaults($linesBefore, $jm->reconcileJob($job['id'])['results']);
check('after the fix every line keeps the status, the reason and the action it had before', $faults === [], json_encode($faults, JSON_UNESCAPED_UNICODE));
$split = (new \Smalot\PdfParser\Parser())->parseFile($finished . '/93247359-1.pdf')->getPages();
check(
    'a split PDF prints the corrected invoice number on a page that carried the wrong one',
    count($split) === 2 && strpos($split[1]->getText(), '93247359-1') !== false,
    'pages=' . count($split)
);

$job = $jm->createJob('PDF only');
$finished = $jm->getJobFinishedDir($job);
copy($sample, $finished . '/multi.pdf');
$rows = (new PdfExtractor())->extractDetailedRows($finished . '/multi.pdf');
$entries = [['filename' => 'multi.pdf', 'storedPath' => $finished . '/multi.pdf', 'items' => $rows]];
$jm->recordExtractedBatch($job['id'], $entries, true);
$billOf = function (array $r): string {
    $page = (int)$r['page_no'];
    return $page <= 2 ? '93247359-1' : ($page === 3 ? '93247359-2' : '93247359-3');
};
$footer = [];
foreach ($rows as $r) {
    $excl = (float)str_replace(',', '', (string)$r['amt_excl_vat']);
    $footer[$billOf($r)] = max($footer[$billOf($r)] ?? 0.0, $excl);
}
$asn = [];
foreach ($rows as $r) {
    if ($r['barcode'] !== '5401132437309') {
        $bill = $billOf($r);
        $asn[] = [
            'invoice_no' => $bill, 'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '', 'selling_price' => 1,
            'total_cost_ex_vat' => $bill === '93247359-3' ? $footer[$bill] - 1000 : $footer[$bill],
        ];
    }
}
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
$linesBefore = $jm->reconcileJob($job['id'])['results'];
$extraProblem = sprintf(
    'บาร์โค้ด 5401132437309 มีใน PDF แต่ไม่มีใน ASN (4 ชิ้น), ยอดรวมไม่ตรง (PDF: %s / ASN: %s)',
    number_format($footer['93247359-3'], 2),
    number_format($footer['93247359-3'] - 1000, 2)
);
$byBill = function (array $lines): array {
    $out = [];
    foreach ($lines as $r) {
        $out[$r['asn']['invoice_no']][$r['status'] . ' | ' . $r['reason'] . ' | ' . $r['action']] = true;
    }
    return array_map('array_keys', $out);
};
$shownBefore = $byBill($linesBefore);
check(
    'on a real PDF a bill holding a barcode its ASN does not list is wrong on every line with that barcode and its pieces before the amount problem; the other bills of the file wait for the fix',
    count($shownBefore['93247359-3'] ?? []) === 1 && $shownBefore['93247359-3'][0] === "MISMATCH | $extraProblem | "
        && strpos(implode('', $shownBefore['93247359-1'] ?? ['MISMATCH']), 'MISMATCH') === false && strpos(implode('', $shownBefore['93247359-2'] ?? ['MISMATCH']), 'MISMATCH') === false
        && strpos(json_encode($shownBefore, JSON_UNESCAPED_UNICODE), 'PENDING_FIX') !== false,
    json_encode($shownBefore, JSON_UNESCAPED_UNICODE)
);
$remarkBefore = dashboardRows($jm, $job['id'])['93247359-3'] ?? [];
(new PdfFixer(Database::getConnection()))->fixJob($job['id']);
$linesAfter = $jm->reconcileJob($job['id'])['results'];
$faults = beforeFixFaults($linesBefore, $linesAfter);
$remarkAfter = dashboardRows($jm, $job['id'])['93247359-3'] ?? [];
check(
    'after the fix that bill is wrong with the same problem, the waiting bills are complete and fixed, and its dashboard remark is the problem, after the fix what the fix did, with no solution',
    $faults === [] && ($remarkBefore['problem'] ?? '') === $extraProblem && ($remarkBefore['solution'] ?? null) === '' && ($remarkAfter['status'] ?? '') === 'MISMATCH'
        && ($remarkAfter['problem'] ?? '') === $extraProblem && ($remarkAfter['solution'] ?? null) === '',
    json_encode([$faults, $remarkBefore['problem'] ?? null, $remarkAfter['note'] ?? null, $remarkAfter['problem'] ?? null], JSON_UNESCAPED_UNICODE)
);

$job = $jm->createJob('Footer swap');
$finished = $jm->getJobFinishedDir($job);
copy($sample, $finished . '/multi.pdf');
$rows = (new PdfExtractor())->extractDetailedRows($finished . '/multi.pdf');
$entries = [['filename' => 'multi.pdf', 'storedPath' => $finished . '/multi.pdf', 'items' => $rows]];
$jm->recordExtractedBatch($job['id'], $entries, true);
// Each bill's ASN carries the totals of its own last page. Then the footers of page 2, the last of 93247359-1 (printed
// as -2, and page 1 repeats its footer), and of page 5, the last of 93247359-3, are exchanged in the stored rows, as a
// PDF that printed each bill's totals under the other would read
$lastTotals = [];
foreach ($rows as $r) {
    if ($r['amt_excl_vat'] !== 'Null') {
        $lastTotals[$billOf($r)] = ['total_cost_ex_vat' => (float)str_replace(',', '', $r['amt_excl_vat']), 'total_vat' => (float)str_replace(',', '', $r['vat_amount'])];
    }
}
$asn = [];
foreach ($rows as $r) {
    $asn[] = ['invoice_no' => $billOf($r), 'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '', 'selling_price' => 1] + $lastTotals[$billOf($r)];
}
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
$footerFields = array_keys(FixPlan::CLEARED_FOOTER);
$footerOfPage = function (int $page) use ($pdo, $job, $footerFields): array {
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $footerFields) . ' FROM job_items WHERE job_id = ? AND page_no = ? LIMIT 1');
    $stmt->execute([$job['id'], $page]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
};
[$footer2, $footer5] = [$footerOfPage(2), $footerOfPage(5)];
$setFooter = $pdo->prepare('UPDATE job_items SET ' . implode(' = ?, ', $footerFields) . ' = ? WHERE job_id = ? AND page_no = ?');
$setFooter->execute(array_merge(array_values($footer5), [$job['id'], 2]));
$setFooter->execute(array_merge(array_values($footer2), [$job['id'], 5]));
// Read off the PDF: -1 has 32,790.00 of lines and prints 5,990.00 off, 28%, 18,033.65 (VAT 1,262.35); -3 has 50,320.00
// and prints 25,160.00 off, 20%, 18,811.25 (VAT 1,316.75). (32,790 - 25,160) x 0.80 / 1.07 = 5,704.67 and
// (50,320 - 5,990) x 0.72 / 1.07 = 29,829.53
$swapped = [
    '93247359-1' => 'ยอดรวมท้ายบิลเป็นของบิล 93247359-3 (ยอดรวมก่อน VAT ท้ายบิล: 18,811.25 / จากรายการของบิลนี้: 5,704.67), '
        . 'ยอดรวมไม่ตรง (PDF: 18,811.25 / ASN: 18,033.65), VAT ไม่ตรง (PDF: ฿1,316.75 / ASN: ฿1,262.35)',
    '93247359-3' => 'ยอดรวมท้ายบิลเป็นของบิล 93247359-1 (ยอดรวมก่อน VAT ท้ายบิล: 18,033.65 / จากรายการของบิลนี้: 29,829.53), '
        . 'ยอดรวมไม่ตรง (PDF: 18,033.65 / ASN: 18,811.25), VAT ไม่ตรง (PDF: ฿1,262.35 / ASN: ฿1,316.75)',
];
$swapRemarks = function () use ($jm, $job): array {
    return array_map(function ($row) {
        return [$row['status'], $row['problem'], $row['solution']];
    }, array_intersect_key(dashboardRows($jm, $job['id']), ['93247359-1' => true, '93247359-3' => true]));
};
$linesBefore = $jm->reconcileJob($job['id'])['results'];
$shownBefore = $byBill($linesBefore);
$remarkBefore = $swapRemarks();
check(
    'on a real PDF two bills whose footers are exchanged each name the other on every line before the fix, a mislabelled page and a repeated footer or not, '
        . 'then the amount and the VAT problem their ASN brings, with no solution; the dashboard says the same, and the bill between them still waits',
    ($shownBefore['93247359-1'] ?? null) === ['MISMATCH | ' . $swapped['93247359-1'] . ' | '] && ($shownBefore['93247359-3'] ?? null) === ['MISMATCH | ' . $swapped['93247359-3'] . ' | ']
        && strpos(implode('', $shownBefore['93247359-2'] ?? ['x']), 'PENDING_FIX') === 0
        && $remarkBefore === ['93247359-1' => ['MISMATCH', $swapped['93247359-1'], ''], '93247359-3' => ['MISMATCH', $swapped['93247359-3'], '']],
    json_encode([$shownBefore, $remarkBefore], JSON_UNESCAPED_UNICODE)
);
(new PdfFixer(Database::getConnection()))->fixJob($job['id']);
$linesAfter = $jm->reconcileJob($job['id'])['results'];
$faults = beforeFixFaults($linesBefore, $linesAfter);
check(
    'after the fix every line has the status and the problem it showed before, the waiting bill complete, and the dashboard still names the bill each footer belongs to',
    $faults === [] && $byBill($linesAfter)['93247359-1'] === $shownBefore['93247359-1'] && $byBill($linesAfter)['93247359-3'] === $shownBefore['93247359-3'] && $swapRemarks() === $remarkBefore,
    json_encode([$faults, $swapRemarks()], JSON_UNESCAPED_UNICODE)
);

$sharedSample = __DIR__ . '/fixtures/CDS ZFP (ZKE)/RM Document-Customer Billing(PDF)-0093248266 4.pdf';
if (is_file($sharedSample)) {
    $job = $jm->createJob('Shared barcode');
    $finished = $jm->getJobFinishedDir($job);
    copy($sharedSample, $finished . '/shared.pdf');
    $rows = (new PdfExtractor())->extractDetailedRows($finished . '/shared.pdf');
    $entries = [['filename' => 'shared.pdf', 'storedPath' => $finished . '/shared.pdf', 'items' => $rows]];
    $jm->recordExtractedBatch($job['id'], $entries, true);
    $asn = [];
    foreach ($rows as $r) {
        $asn[] = [
            'invoice_no' => (int)$r['page_no'] <= 2 ? '93248266-1' : '93248266-2', 'ean_upc' => $r['barcode'], 'quantity' => $r['units'],
            'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1,
        ];
    }
    $jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
    (new PdfFixer(Database::getConnection()))->fixJob($job['id']);
    $pages = [];
    foreach ($jm->getJobItems($job['id']) as $it) {
        $pages[(int)$it['page_no']][$it['tax_invoice']] = true;
    }
    check(
        'the fixer keeps a barcode shared by two invoices on the page it continues',
        array_keys($pages[2] ?? []) === ['93248266-1'] && array_keys($pages[3] ?? []) === ['93248266-2'],
        json_encode(array_map('array_keys', $pages))
    );
}

function fixRecords(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT invoice_no FROM job_invoice_fixes WHERE job_id = ? ORDER BY invoice_no');
    $stmt->execute([$jobId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function fixSteps(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT id, kind, filename, page_no, invoice_no, from_value FROM job_invoice_fix_steps WHERE job_id = ? ORDER BY kind, page_no');
    $stmt->execute([$jobId]);
    return array_map(function ($s) {
        return "{$s['kind']} {$s['filename']} p{$s['page_no']} {$s['invoice_no']}" . ($s['from_value'] !== '' ? " from {$s['from_value']}" : '') . " #{$s['id']}";
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function withoutIds(array $steps): array
{
    return array_map(function ($s) {
        return preg_replace('/ #\d+$/', '', $s);
    }, $steps);
}

function dashboardRows(JobManager $jm, int $jobId): array
{
    $rows = [];
    foreach ($jm->getInvoiceDashboard($jobId)['invoices'] as $row) {
        $rows[$row['invoice_no']] = $row;
    }
    return $rows;
}

function planOf(PDO $pdo, JobManager $jm, int $jobId): FixPlan
{
    $stmt = $pdo->prepare('SELECT invoice_no, ean_upc, quantity FROM job_asn_records WHERE job_id = ?');
    $stmt->execute([$jobId]);
    return new FixPlan($stmt->fetchAll(PDO::FETCH_ASSOC), $jm->getJobItems($jobId));
}

function loggedSteps(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT kind, filename, page_no AS page, invoice_no AS invoice, from_value AS "from" FROM job_invoice_fix_steps WHERE job_id = ?');
    $stmt->execute([$jobId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function stepKeys(array $steps, array $invoices): array
{
    $keys = [];
    foreach ($steps as $s) {
        $from = (string)($s['from'] ?? '');
        if (in_array((string)$s['invoice'], $invoices, true) || in_array($from, $invoices, true)) {
            $keys[] = "{$s['kind']} {$s['filename']} p{$s['page']} from '{$from}' {$s['invoice']}";
        }
    }
    sort($keys);
    return $keys;
}

/** Page numbers a remark names: "หน้า 1-3 และ 5", "หน้าที่ 1-3 และ 5" and "หน้าที่ 1 ถึง 3 และ 5" give 1, 2, 3, 5 */
function pagesNamed(string $text): array
{
    $pages = [];
    preg_match_all('/หน้า(?:ที่)? (\d+(?:(?:-| ถึง )\d+)?(?: และ \d+(?:(?:-| ถึง )\d+)?)*)/u', $text, $m);
    foreach ($m[1] as $list) {
        foreach (explode(' และ ', $list) as $range) {
            $ends = preg_split('/-| ถึง /u', $range);
            $pages = array_merge($pages, range((int)$ends[0], (int)end($ends)));
        }
    }
    $pages = array_values(array_unique($pages));
    sort($pages);
    return $pages;
}

/**
 * Compare lines whose status before the fix is not the one they have after it (waiting counts as complete), or whose
 * wrong reason changed. A line is complete and fixed after the fix exactly when it waited, or already was, before it;
 * line by line and bill by bill.
 *
 * @param array $before lines of JobManager::reconcileJob(), which carry the fixed flag, and so is $after
 */
function beforeFixFaults(array $before, array $after): array
{
    $later = [];
    foreach ($after as $r) {
        $later[$r['asn']['invoice_no'] . ' ' . $r['asn']['ean_upc']] = $r;
    }
    $faults = count($before) === count($later) ? [] : ['line count' => [count($before), count($later)]];
    foreach ($before as $r) {
        $key = $r['asn']['invoice_no'] . ' ' . $r['asn']['ean_upc'];
        $then = $later[$key] ?? ['status' => 'missing', 'reason' => '', 'action' => '', 'fixed' => false];
        $reasonKept = $r['status'] !== 'MISMATCH' || [$r['reason'], $r['action']] === [$then['reason'], $then['action']];
        $waited = $r['status'] === 'PENDING_FIX' || ($r['status'] === 'MATCH' && $r['fixed']);
        if ($then['status'] !== ($r['status'] === 'PENDING_FIX' ? 'MATCH' : $r['status']) || !$reasonKept || $waited !== ($then['status'] === 'MATCH' && $then['fixed'])) {
            $faults[$key] = [$r['status'], $r['fixed'], $then['status'], $then['fixed'], $r['reason'], $then['reason']];
        }
    }
    $waiting = array_map('strval', array_keys(array_filter(AsnReconciler::invoiceStatuses($before), function ($s) {
        return $s === 'PENDING_FIX';
    })));
    $waiting = array_values(array_unique(array_merge($waiting, fixedComplete($before))));
    sort($waiting);
    if ($waiting !== fixedComplete($after)) {
        $faults['bills waiting or fixed before, bills complete and fixed after'] = [$waiting, fixedComplete($after)];
    }
    return $faults;
}

function fixedComplete(array $lines): array
{
    $fixed = [];
    foreach ($lines as $r) {
        if ($r['fixed']) {
            $fixed[$r['asn']['invoice_no']] = true;
        }
    }
    $bills = array_map('strval', array_keys(array_intersect_key(array_filter(AsnReconciler::invoiceStatuses($lines), function ($s) {
        return $s === 'MATCH';
    }), $fixed)));
    sort($bills);
    return $bills;
}

$job = $jm->createJob('Dashboard counts');
$dashAsn = [];
$dashItems = [];
$dashLine = function (string $asnInv, string $pdfInv, string $barcode, int $asnQty) use (&$dashAsn, &$dashItems) {
    $dashAsn[] = ['invoice_no' => $asnInv, 'ean_upc' => $barcode, 'quantity' => $asnQty, 'dest_store_no' => '10077', 'selling_price' => 100, 'total_cost_ex_vat' => 750.00, 'total_vat' => 52.5];
    $dashItems[] = [
        'tax_invoice' => $pdfInv, 'page_no' => count(array_unique(array_column($dashAsn, 'invoice_no'))), 'barcode' => $barcode, 'units' => 1, 'amount' => 1070, 'branch_code' => '00077',
        'total_amount' => '1,070.00', 'amt_excl_vat' => '750.00', 'vat_amount' => '52.50', 'amt_incl_vat' => '802.50', 'ship_to_party' => '', 'address' => '',
    ];
};
$dashLine('9300001-1', '9300001-1', '8850000000101', 1);
$dashLine('9300001-1', '9300001-1', '8850000000111', 1);
$dashLine('9300001-1', '9300001-1', '8850000000121', 1);
$dashLine('9300002-1', '9300002-1', '8850000000102', 1);
$dashLine('9300003-1', '9300003-2', '8850000000103', 1);
$dashLine('9300004-1', '9300004-1', '8850000000104', 1);
$dashLine('9300004-1', '9300004-1', '8850000000114', 2);
$dashLine('9300005-1', '9300005-1', '8850000000105', 2);
$dashLine('9300006-1', '9300006-2', '8850000000106', 1);
$jm->saveAsnRecords($job['id'], 't.xlsx', $dashAsn);
$entries = [
    ['filename' => 'one.pdf', 'storedPath' => $root . '/one.pdf', 'items' => array_slice($dashItems, 0, 3)],
    ['filename' => 'dash.pdf', 'storedPath' => $root . '/dash.pdf', 'items' => array_slice($dashItems, 3)],
];
$jm->recordExtractedBatch($job['id'], $entries, true);

$dash = $jm->getInvoiceDashboard($job['id']);
check(
    'before the first comparison only the invoice total is known, counted as invoices not rows',
    $dash['compared'] === false && $dash['total'] === 6 && $dash['complete'] === null && $dash['fixed'] === null && $dash['error'] === null && $dash['invoices'] === [],
    json_encode($dash)
);

$pdo->exec("INSERT INTO job_invoice_fixes (job_id, invoice_no) VALUES ({$job['id']}, '9300002-1'), ({$job['id']}, '9300005-1'), ({$job['id']}, '9300006-1')");
$jm->reconcileJob($job['id']);
$dash = $jm->getInvoiceDashboard($job['id']);
$dashRows = [];
foreach ($dash['invoices'] as $dashRow) {
    $dashRows[$dashRow['invoice_no']] = $dashRow;
}
unset($dash['invoices']);
check(
    'every count is a number of invoices, the worst line decides, a fix record only counts once the invoice stopped waiting',
    $dash === [
        'compared' => true, 'total' => 6, 'complete' => 1, 'needs_fix' => 4, 'not_fixed' => 2, 'fixed' => 2, 'error' => 2, 'error_after_fix' => 1,
    ],
    json_encode($dash)
);
check('opening the dashboard leaves the comparison state alone', (int)$jm->getJob($job['id'])['asn_reconciled'] === 1);
check('the table has one row per invoice number in invoice order', array_keys($dashRows) === ['9300001-1', '9300002-1', '9300003-1', '9300004-1', '9300005-1', '9300006-1'], json_encode(array_keys($dashRows)));
$row = $dashRows['9300001-1'] ?? [];
check(
    'a row carries branch code, store, pieces, pages, the ASN Total Cost Ex VAT and the result of the invoice, and no PDF net amount any more',
    ($row['branch_code'] ?? '') === '00077' && ($row['store_no'] ?? '') === '10077' && ($row['units'] ?? 0) === 3 && ($row['pages'] ?? 0) === 1
        && ($row['total_cost_ex_vat'] ?? 0) === 750.0 && !array_key_exists('net_amount', $row) && ($row['status'] ?? '') === 'MATCH' && ($row['fixed'] ?? null) === false
        && [$row['note'] ?? 'x', $row['problem'] ?? 'x', $row['solution'] ?? 'x'] === ['', '', ''] && ($row['source_files'] ?? null) === ['one.pdf'],
    json_encode($row, JSON_UNESCAPED_UNICODE)
);
$row = $dashRows['9300004-1'] ?? [];
check(
    'a wrong invoice is one row, counted in pieces; its problem names the barcode of the failing line only and it has no solution',
    ($row['status'] ?? '') === 'MISMATCH' && ($row['units'] ?? 0) === 2 && ($row['note'] ?? 'x') === ''
        && ($row['problem'] ?? '') === 'บาร์โค้ด 8850000000114: จำนวนไม่ตรง (PDF: 1 ชิ้น / ASN: 2 ชิ้น)' && ($row['solution'] ?? 'x') === '',
    json_encode($row, JSON_UNESCAPED_UNICODE)
);
check(
    'a wrong invoice that the fix only splits out of a shared file keeps its content reason, with nothing about the split',
    !preg_match('/ไฟล์ต้นฉบับ|แยก/u', ($row['note'] ?? '') . ($row['problem'] ?? '') . ($row['solution'] ?? '') . ($row['note_detail'] ?? '')),
    json_encode($row, JSON_UNESCAPED_UNICODE)
);
$row = $dashRows['9300003-1'] ?? [];
check(
    'an invoice whose pages carry another number has no PDF figures yet, only the ASN cost, and says why it waits and how the fix puts it right',
    ($row['status'] ?? '') === 'PENDING_FIX' && array_key_exists('units', $row) && $row['units'] === null && $row['pages'] === null && $row['total_cost_ex_vat'] === 750.0
        && $row['branch_code'] === null && ($row['store_no'] ?? '') === '10077' && ($row['source_files'] ?? null) === []
        && ($row['problem'] ?? '') === 'หน้า 3 คาดว่าเลขบิลผิด เนื่องจากรายการถูกต้องตาม ASN' && ($row['solution'] ?? '') === 'แก้ไขเลขบิลของหน้า 3, แยกหน้าที่ 3 ออกมาเป็นอีกไฟล์',
    json_encode($row, JSON_UNESCAPED_UNICODE)
);
check(
    'an invoice fixed before steps were logged only says it was split from the pages its rows are on; one still wrong says that too, then its problem',
    ($dashRows['9300002-1']['note'] ?? '') === 'แยกออกมาจากหน้า 2' && ($dashRows['9300002-1']['status'] ?? '') === 'MATCH' && ($dashRows['9300002-1']['fixed'] ?? null) === true
        && ($dashRows['9300002-1']['problem'] ?? 'x') === ''
        && ($dashRows['9300005-1']['note'] ?? '') === 'แยกออกมาจากหน้า 5' && strpos($dashRows['9300005-1']['problem'] ?? '', 'จำนวนไม่ตรง') === 0
        && strpos($dashRows['9300006-1']['note'] ?? '', 'แยกออกมา') === false && ($dashRows['9300006-1']['fixed'] ?? null) === false,
    json_encode([$dashRows['9300002-1'] ?? null, $dashRows['9300005-1'] ?? null, $dashRows['9300006-1']['fixed'] ?? null], JSON_UNESCAPED_UNICODE)
);

$pdo->exec("UPDATE job_asn_records SET total_cost_ex_vat = 0 WHERE job_id = {$job['id']} AND (invoice_no = '9300002-1' OR ean_upc = '8850000000104')");
$costs = array_column($jm->getInvoiceDashboard($job['id'])['invoices'], 'total_cost_ex_vat', 'invoice_no');
check(
    'the Total Cost Ex VAT of a row is the first positive one among the ASN lines of the invoice, null when no line has one',
    $costs === ['9300001-1' => 750.0, '9300002-1' => null, '9300003-1' => 750.0, '9300004-1' => 750.0, '9300005-1' => 750.0, '9300006-1' => 750.0],
    json_encode($costs)
);

$empty = $jm->createJob('Dashboard empty');
$dash = $jm->getInvoiceDashboard($empty['id']);
check('a job without ASN data has no invoices', $dash['total'] === 0 && $dash['compared'] === false, json_encode($dash));

$res = api('get_job_dashboard', [], ['job_id' => $job['id']]);
check('the API serves the same dashboard', ($res['status'] ?? '') === 'success' && ($res['data']['needs_fix'] ?? -1) === 4 && ($res['data']['total'] ?? -1) === 6, json_encode($res));

$job = $jm->createJob('Fix records');
$finished = $jm->getJobFinishedDir($job);
copy($sample, $finished . '/sample.pdf');
$rows = (new PdfExtractor())->extractDetailedRows($finished . '/sample.pdf');
$entries = [['filename' => 'sample.pdf', 'storedPath' => $finished . '/sample.pdf', 'items' => $rows]];
$jm->recordExtractedBatch($job['id'], $entries, true);
$invoiceOf = function (int $page): string {
    return $page <= 2 ? '93247359-1' : ($page === 3 ? '93247359-2' : '93247359-3');
};
$lastFooter = [];
foreach ($rows as $r) {
    if (!in_array($r['amt_excl_vat'], ['Null', '-', ''], true)) {
        $lastFooter[$invoiceOf((int)$r['page_no'])] = ['total_cost_ex_vat' => (float)str_replace(',', '', $r['amt_excl_vat']), 'total_vat' => (float)str_replace(',', '', $r['vat_amount'])];
    }
}
$asn = [];
foreach ($rows as $r) {
    $inv = $invoiceOf((int)$r['page_no']);
    $asn[] = [
        'invoice_no' => $inv, 'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '', 'selling_price' => 1,
    ] + ($inv === '93247359-3' ? ['total_cost_ex_vat' => 1] : $lastFooter[$inv]);
}
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
$linesBefore = $jm->reconcileJob($job['id'])['results'];
$statusBefore = AsnReconciler::invoiceStatuses($linesBefore);
check('the sample has two invoices waiting for fix and one wrong', array_values($statusBefore) === ['PENDING_FIX', 'PENDING_FIX', 'MISMATCH'], json_encode($statusBefore));
check('nothing is recorded before the fix button is pressed', fixRecords($pdo, $job['id']) === []);
$exportLines = $linesBefore;

$pending = array_map('strval', array_keys(array_filter($statusBefore, function ($s) {
    return $s === 'PENDING_FIX';
})));
$planBefore = planOf($pdo, $jm, $job['id']);
$texts = [];
foreach ($linesBefore as $r) {
    if ($r['status'] === 'PENDING_FIX') {
        $texts[$r['asn']['invoice_no']][json_encode([$r['reason'], $r['action']], JSON_UNESCAPED_UNICODE)] = [$r['reason'], $r['action']];
    }
}
ksort($texts);
$texts = array_map('array_values', $texts);
$reason1 = 'หน้า 2 คาดว่าเลขบิลผิด เนื่องจากรวมกับหน้า 1 แล้วรายการถูกต้องตาม ASN';
$action1 = 'แก้ไขเลขบิลของหน้า 2, ลบยอดรวมของหน้าที่ 1, แยกหน้าที่ 1 ถึง 2 ออกมาเป็นอีกไฟล์';
$action2 = 'ย้ายหน้า 2 ไปเป็นบิล 93247359-1, แยกหน้าที่ 3 ออกมาเป็นอีกไฟล์';
check(
    'every line of a bill waiting for the fix has the user\'s problem sentence, told the same by the bill whose number the page prints, and a solution in the order renumber, total, split; the bill giving the page away says where it goes',
    $texts === ['93247359-1' => [[$reason1, $action1]], '93247359-2' => [[$reason1, $action2]]],
    json_encode($texts, JSON_UNESCAPED_UNICODE)
);

// From the plan: a page printed with another number makes its owner and the bill whose number it prints both name
// every page of the owner, only the misnumbered ones when the owner stays wrong; a total on a page that is not the
// last is a problem of its own on a bill without such a page
$problemPages = [];
$owners = [];
$takers = [];
foreach ($planBefore->files() as $file) {
    foreach ($file['pages'] as $pageNo => $page) {
        if (!empty($page['renumber'])) {
            $owner = (string)$page['invoice'];
            $owners[$owner] = ($statusBefore[$owner] ?? '') === 'MISMATCH' ? array_merge($owners[$owner] ?? [], [$pageNo]) : $file['invoices'][$owner];
            $takers[(string)$page['printed']][$owner] = true;
        }
    }
}
foreach ($planBefore->files() as $file) {
    foreach ($file['pages'] as $pageNo => $page) {
        $inv = (string)$page['invoice'];
        if (isset($owners[$inv])) {
            $problemPages[$inv] = $owners[$inv];
        } elseif (!empty($page['clear_footer'])) {
            $problemPages[$inv][] = $pageNo;
        }
    }
}
foreach ($takers as $giver => $byOwner) {
    foreach (array_keys($byOwner) as $owner) {
        $problemPages[$giver] = array_merge($problemPages[$giver] ?? [], $owners[$owner]);
    }
}
$plannedPages = [];
foreach ($planBefore->steps() as $s) {
    foreach (array_unique(array_filter([(string)$s['invoice'], (string)($s['from'] ?? '')], 'strlen')) as $inv) {
        $plannedPages[$inv][] = (int)$s['page'];
    }
}
$sortedUnique = function (array $values): array {
    $values = array_values(array_unique($values));
    sort($values);
    return $values;
};
$textFaults = [];
foreach (array_unique(array_merge($pending, array_map('strval', array_keys($texts)))) as $inv) {
    foreach ($texts[$inv] ?? [['', '']] as [$reason, $action]) {
        $destinations = array_filter(array_keys($takers[$inv] ?? []), function ($owner) use ($action) {
            return strpos($action, 'ไปเป็นบิล ' . $owner) === false;
        });
        if (pagesNamed($reason) !== $sortedUnique($problemPages[$inv] ?? [-1]) || !empty($destinations)
            || preg_match(FORBIDDEN_REMARK_WORDS, $reason . $action) || pagesNamed($action) !== $sortedUnique($plannedPages[$inv] ?? [-1])) {
            $textFaults[] = [$inv, $reason, $action];
        }
    }
}
check(
    'the problem of a waiting bill names exactly the pages the plan gives it, and its solution every page the plan touches and the bill a page goes to; neither names a button',
    !empty($pending) && $textFaults === [],
    json_encode([$problemPages, $plannedPages, $textFaults], JSON_UNESCAPED_UNICODE)
);
$dashRows = dashboardRows($jm, $job['id']);
$exportDash = array_values($dashRows);
$badNotes = [];
foreach ($pending as $inv) {
    $row = $dashRows[$inv] ?? [];
    if ([$row['note'] ?? null, $row['problem'] ?? null, $row['solution'] ?? null] !== ['', $texts[$inv][0][0] ?? '', $texts[$inv][0][1] ?? '']
        || preg_match(FORBIDDEN_REMARK_WORDS, ($row['problem'] ?? '') . ($row['solution'] ?? '') . ($row['note_detail'] ?? ''))) {
        $badNotes[$inv] = $row;
    }
}
check('the dashboard remark of a waiting bill is the problem and the solution of its lines, without a button', $badNotes === [], json_encode($badNotes, JSON_UNESCAPED_UNICODE));

(new PdfFixer($pdo))->fixJob($job['id']);
$faults = beforeFixFaults($linesBefore, $jm->reconcileJob($job['id'])['results']);
check(
    'before the fix every line already had the status it has after it (a waiting line is complete now) and a wrong line its reason',
    $faults === [] && in_array('PENDING_FIX', $statusBefore, true) && in_array('MISMATCH', $statusBefore, true),
    json_encode($faults, JSON_UNESCAPED_UNICODE)
);
check(
    'the fix records every invoice it worked on: the renumbered one, the one that lost a page and the one only split out of the shared file',
    fixRecords($pdo, $job['id']) === ['93247359-1', '93247359-2', '93247359-3'],
    json_encode(fixRecords($pdo, $job['id']))
);
$expectedSteps = [
    'clear_footer sample.pdf p1 93247359-1',
    'renumber sample.pdf p2 93247359-1 from 93247359-2',
    'split sample.pdf p1 93247359-1',
    'split sample.pdf p2 93247359-1',
    'split sample.pdf p3 93247359-2',
    'split sample.pdf p4 93247359-3',
    'split sample.pdf p5 93247359-3',
];
$steps = fixSteps($pdo, $job['id']);
check(
    'the fix logs the renumbered page with its old and new number, the footer it cleared and the pages of every split',
    withoutIds($steps) === $expectedSteps,
    json_encode($steps)
);
$allInvoices = array_map('strval', array_keys($statusBefore));
check(
    'for every invoice that waited, the plan made before the fix holds exactly the steps the fix logged, and so does the whole plan',
    count(stepKeys(loggedSteps($pdo, $job['id']), $pending)) > 0
        && stepKeys($planBefore->steps(), $pending) === stepKeys(loggedSteps($pdo, $job['id']), $pending)
        && stepKeys($planBefore->steps(), $allInvoices) === stepKeys(loggedSteps($pdo, $job['id']), $allInvoices),
    json_encode([stepKeys($planBefore->steps(), $allInvoices), stepKeys(loggedSteps($pdo, $job['id']), $allInvoices)], JSON_UNESCAPED_UNICODE)
);
$planAfter = planOf($pdo, $jm, $job['id']);
$changesLeft = array_filter($planAfter->steps(), function ($s) {
    return $s['kind'] !== 'split';
});
$textsAfter = $planAfter->describe($allInvoices, (new FixLog($pdo))->splitDone($job['id']));
check(
    'a plan made again after the fix finds no page to renumber, no footer to clear, nothing wrong and nothing to wait for, only the splits the fix redoes',
    $changesLeft === [] && array_filter(array_column($textsAfter, 'problem'), 'strlen') === [] && array_filter(array_column($textsAfter, 'reason'), 'strlen') === []
        && array_diff(stepKeys($planAfter->steps(), $allInvoices), stepKeys(loggedSteps($pdo, $job['id']), $allInvoices)) === [],
    json_encode([$changesLeft, $textsAfter], JSON_UNESCAPED_UNICODE)
);
$dash = $jm->getInvoiceDashboard($job['id']);
check(
    'the dashboard keeps the number waiting for fix constant after the fix',
    $dash['needs_fix'] === 3 && $dash['fixed'] === 3 && $dash['not_fixed'] === 0 && $dash['error'] === 1 && $dash['error_after_fix'] === 1,
    json_encode($dash)
);
$note1 = 'แก้เลขบิลจากหน้า 2 (93247359-2 เป็น 93247359-1), ลบยอดรวมท้ายบิลจากหน้า 1, แยกออกมาจากหน้า 1-2';
$note2 = 'หน้า 2 ถูกย้ายไปเป็น 93247359-1, แยกออกมาจากหน้า 3';
$note3 = 'แยกออกมาจากหน้า 4-5';
$dashRows = dashboardRows($jm, $job['id']);
$exportDash = array_merge($exportDash, array_values($dashRows));
check(
    'a fixed invoice that is still wrong tells what the fix did, then its problem with no solution; a fixed complete one only what the fix did',
    ($dashRows['93247359-1']['note'] ?? '') === $note1 && ($dashRows['93247359-2']['note'] ?? '') === $note2 && ($dashRows['93247359-1']['problem'] ?? 'x') === ''
        && ($dashRows['93247359-3']['note'] ?? '') === $note3 && preg_match('/^ยอดรวมไม่ตรง \(PDF: [\d,]+\.\d{2} \/ ASN: 1\.00\)$/u', $dashRows['93247359-3']['problem'] ?? '') === 1
        && ($dashRows['93247359-3']['solution'] ?? 'x') === '',
    json_encode(array_map(function ($r) {
        return [$r['note'], $r['problem'], $r['solution']];
    }, $dashRows), JSON_UNESCAPED_UNICODE)
);
check(
    'the remark of the renumbered invoice names all three things the fix did, renumber, footer and split, with no "แก้ไขแล้ว" in front',
    strpos($dashRows['93247359-1']['note'] ?? '', 'แก้เลขบิลจากหน้า 2 (93247359-2 เป็น 93247359-1)') === 0
        && strpos($dashRows['93247359-1']['note'] ?? '', 'ลบยอดรวมท้ายบิลจากหน้า 1') !== false && strpos($dashRows['93247359-1']['note'] ?? '', 'แยกออกมาจากหน้า 1-2') !== false
        && strpos(json_encode(array_column($dashRows, 'note'), JSON_UNESCAPED_UNICODE), 'แก้ไขแล้ว') === false,
    $dashRows['93247359-1']['note'] ?? ''
);
(new PdfFixer($pdo))->fixJob($job['id']);
check('pressing fix again keeps the record', fixRecords($pdo, $job['id']) === ['93247359-1', '93247359-2', '93247359-3']);
check('pressing fix again leaves the same step rows', fixSteps($pdo, $job['id']) === $steps, json_encode(fixSteps($pdo, $job['id'])));

foreach ($jm->getJobItems($job['id']) as $it) {
    if (!in_array($it['amt_excl_vat'], ['Null', '-', ''], true)) {
        $stmt = $pdo->prepare('UPDATE job_asn_records SET total_cost_ex_vat = ?, total_vat = ? WHERE job_id = ? AND invoice_no = ?');
        $stmt->execute([(float)str_replace(',', '', $it['amt_excl_vat']), (float)str_replace(',', '', $it['vat_amount']), $job['id'], $it['tax_invoice']]);
    }
}
$dashRows = dashboardRows($jm, $job['id']);
check(
    'a fixed invoice says page by page what the fix did, and the invoice that lost a page says where it went',
    ($dashRows['93247359-1']['note'] ?? '') === $note1 && ($dashRows['93247359-2']['note'] ?? '') === $note2,
    json_encode(array_column($dashRows, 'note'), JSON_UNESCAPED_UNICODE)
);
check(
    'the last invoice of the shared file, only split out, is fixed and its remark names only the pages it was split from',
    ($dashRows['93247359-3']['note'] ?? '') === $note3 && ($dashRows['93247359-3']['fixed'] ?? null) === true && ($dashRows['93247359-3']['status'] ?? '') === 'MATCH',
    json_encode($dashRows['93247359-3'] ?? null, JSON_UNESCAPED_UNICODE)
);
check(
    'every invoice row names its original PDF and, with no store in the ASN, the store number of its Ship to Party',
    array_column($dashRows, 'source_files') === [['sample.pdf'], ['sample.pdf'], ['sample.pdf']] && array_column($dashRows, 'store_no') === ['10166', '10166', '10166']
        && array_column($dashRows, 'branch_code') === ['00065', '00065', '00065'],
    json_encode(array_map(function ($r) {
        return [$r['source_files'], $r['store_no'], $r['branch_code']];
    }, $dashRows))
);
$lines = [];
foreach ($jm->reconcileJob($job['id'])['results'] as $r) {
    $lines[$r['asn']['invoice_no']][json_encode([$r['status'], $r['fixed'], $r['fix_note'], $r['source_files']], JSON_UNESCAPED_UNICODE)] = true;
}
ksort($lines);
check(
    'every compare line of a fixed invoice carries the fix flag, its fix text and its PDF',
    array_map('array_keys', $lines) === [
        '93247359-1' => [json_encode(['MATCH', true, $note1, ['sample.pdf']], JSON_UNESCAPED_UNICODE)],
        '93247359-2' => [json_encode(['MATCH', true, $note2, ['sample.pdf']], JSON_UNESCAPED_UNICODE)],
        '93247359-3' => [json_encode(['MATCH', true, $note3, ['sample.pdf']], JSON_UNESCAPED_UNICODE)],
    ],
    json_encode(array_map('array_keys', $lines), JSON_UNESCAPED_UNICODE)
);

$ids = [];
foreach ($jm->getJobItems($job['id']) as $it) {
    if ($it['tax_invoice'] === '93247359-1') {
        $ids[] = (int)$it['id'];
    }
}
$jm->deleteJobItems($job['id'], $ids);
check('removing the rows of an invoice drops its fix record', fixRecords($pdo, $job['id']) === ['93247359-2', '93247359-3'], json_encode(fixRecords($pdo, $job['id'])));
check(
    'removing the rows of an invoice drops the steps it owns, its renumbered page included',
    withoutIds(fixSteps($pdo, $job['id'])) === ['split sample.pdf p3 93247359-2', 'split sample.pdf p4 93247359-3', 'split sample.pdf p5 93247359-3'],
    json_encode(fixSteps($pdo, $job['id']))
);

$fresh = (new PdfExtractor())->extractDetailedRows($finished . '/sample.pdf');
$jm->recordExtractedFile($job['id'], 'sample.pdf', $finished . '/sample.pdf', $fresh, true);
check('re-extracting a file with overwrite drops the fix records of its invoices', fixRecords($pdo, $job['id']) === []);
check('re-extracting a file with overwrite drops the steps of its invoices', fixSteps($pdo, $job['id']) === []);

(new PdfFixer($pdo))->fixJob($job['id']);
check('fixing the re-extracted file records the fix again', count(fixRecords($pdo, $job['id'])) === 3);
check('fixing the re-extracted file logs the same steps again', withoutIds(fixSteps($pdo, $job['id'])) === $expectedSteps, json_encode(fixSteps($pdo, $job['id'])));
$jm->deleteFileRecords($job['id'], 'sample.pdf');
check('forgetting a file drops the fix records of its invoices', fixRecords($pdo, $job['id']) === []);
check('forgetting a file drops the steps of its invoices', fixSteps($pdo, $job['id']) === []);

$pdo->exec("INSERT INTO job_invoice_fixes (job_id, invoice_no) VALUES ({$job['id']}, '93247359-1')");
$pdo->exec("INSERT INTO job_invoice_fix_steps (job_id, invoice_no, kind, filename, page_no) VALUES ({$job['id']}, '93247359-1', 'split', 'sample.pdf', 1)");
$jm->clearJobData($job['id']);
check('clearing the job data drops every fix record', fixRecords($pdo, $job['id']) === []);
check('clearing the job data drops every fix step', fixSteps($pdo, $job['id']) === []);

$job = $jm->createJob('Split only');
$finished = $jm->getJobFinishedDir($job);
copy($sample, $finished . '/sample.pdf');
$rows = (new PdfExtractor())->extractDetailedRows($finished . '/sample.pdf');
$solo = [[
    'tax_invoice' => '9400001-1', 'page_no' => 1, 'barcode' => '8850000000201', 'units' => 2, 'amount' => 1070, 'branch_code' => '00077',
    'total_amount' => '1,070.00', 'amt_excl_vat' => '1,000.00', 'vat_amount' => '70.00', 'amt_incl_vat' => '1,070.00', 'ship_to_party' => '', 'address' => '',
]];
$entries = [
    ['filename' => 'sample.pdf', 'storedPath' => $finished . '/sample.pdf', 'items' => $rows],
    ['filename' => 'solo.pdf', 'storedPath' => $finished . '/solo.pdf', 'items' => $solo],
];
$jm->recordExtractedBatch($job['id'], $entries, true);
$footers = [];
foreach ($rows as $r) {
    if (!in_array($r['amt_excl_vat'], ['Null', '-', ''], true)) {
        $footers[$invoiceOf((int)$r['page_no'])] = ['total_cost_ex_vat' => (float)str_replace(',', '', $r['amt_excl_vat']), 'total_vat' => (float)str_replace(',', '', $r['vat_amount'])];
    }
}
$asn = [['invoice_no' => '9400001-1', 'ean_upc' => '8850000000201', 'quantity' => 2, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1000, 'total_vat' => 70]];
foreach ($rows as $r) {
    $inv = $invoiceOf((int)$r['page_no']);
    $asn[] = ['invoice_no' => $inv, 'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '', 'selling_price' => 1] + $footers[$inv];
}
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
$billsOf = function (array $lines): array {
    $bills = [];
    foreach ($lines as $r) {
        $bills[$r['asn']['invoice_no']][json_encode([$r['status'], $r['fixed'] ?? null], JSON_UNESCAPED_UNICODE)] = true;
    }
    ksort($bills);
    return array_map('array_keys', $bills);
};
$linesBefore = $jm->reconcileJob($job['id'])['results'];
$shown = [];
foreach ($linesBefore as $r) {
    if ($r['asn']['invoice_no'] !== '9400001-1') {
        $shown[$r['asn']['invoice_no']][json_encode([$r['status'], $r['reason'], $r['action'], $r['can_fix']], JSON_UNESCAPED_UNICODE)] = true;
    }
}
ksort($shown);
$splitReason = 'ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ 4-5';
$splitAction = 'แยกหน้าที่ 4 ถึง 5 ออกมาเป็นอีกไฟล์';
$soloLines = array_values(array_filter($linesBefore, function ($r) {
    return $r['asn']['invoice_no'] === '9400001-1';
}));
check(
    'a right bill the fix only splits out of a file of several bills waits for the fix, says the file holds several bills and its pages, and the solution is the split; bills with page problems keep their texts',
    array_map('array_keys', $shown) === [
        '93247359-1' => [json_encode(['PENDING_FIX', $reason1, $action1, true], JSON_UNESCAPED_UNICODE)],
        '93247359-2' => [json_encode(['PENDING_FIX', $reason1, $action2, true], JSON_UNESCAPED_UNICODE)],
        '93247359-3' => [json_encode(['PENDING_FIX', $splitReason, $splitAction, true], JSON_UNESCAPED_UNICODE)],
    ] && AsnReconciler::invoiceStatuses($linesBefore)['9400001-1'] === 'MATCH',
    json_encode(array_map('array_keys', $shown), JSON_UNESCAPED_UNICODE)
);
check(
    'a complete bill only says ข้อมูลถูกต้อง, as reason and as action',
    count($soloLines) === 1 && [$soloLines[0]['status'], $soloLines[0]['reason'], $soloLines[0]['action']] === ['MATCH', 'ข้อมูลถูกต้อง', 'ข้อมูลถูกต้อง'],
    json_encode($soloLines, JSON_UNESCAPED_UNICODE)
);
$stored = $jm->getJob($job['id']);
check(
    'the stored count of waiting lines includes the split-only bill, so the job opens on the fix step',
    (int)$stored['asn_pending_fix_count'] === count($linesBefore) - 1 && $jm->initialAsnStep($stored) === 'fix',
    (string)$stored['asn_pending_fix_count']
);
$dashBefore = $jm->getInvoiceDashboard($job['id']);
$printPlan = (new PrintManager($jm))->plan($job['id']);
check(
    'before the fix the dashboard counts the split-only bill as waiting with its reason as remark, and only the bill of its own file can be printed',
    [$dashBefore['complete'], $dashBefore['not_fixed'], $dashBefore['fixed'], $dashBefore['needs_fix']] === [1, 3, 0, 3]
        && array_intersect_key(dashboardRows($jm, $job['id'])['93247359-3'] ?? [], ['note' => 1, 'problem' => 1, 'solution' => 1]) === ['note' => '', 'problem' => $splitReason, 'solution' => $splitAction]
        && array_keys($printPlan['ready']) === ['9400001-1'] && $printPlan['skipped']['pending_fix'] === 3,
    json_encode([$dashBefore['complete'], $dashBefore['not_fixed'], $dashBefore['fixed'], $printPlan['ready'], $printPlan['skipped']], JSON_UNESCAPED_UNICODE)
);
(new PdfFixer($pdo))->fixJob($job['id']);
$linesAfter = $jm->reconcileJob($job['id'])['results'];
$exportLines = array_merge($exportLines, $linesAfter);
$faults = beforeFixFaults($linesBefore, $linesAfter);
$dashAfter = $jm->getInvoiceDashboard($job['id']);
check(
    'what waited before the fix is exactly what is complete and fixed after it, and the complete and needing-fix counts do not move',
    $faults === [] && $dashAfter['complete'] === $dashBefore['complete'] && $dashAfter['needs_fix'] === $dashBefore['needs_fix'] && $dashAfter['fixed'] === 3,
    json_encode([$faults, $dashAfter['complete'], $dashAfter['fixed']], JSON_UNESCAPED_UNICODE)
);
(new PdfFixer($pdo))->fixJob($job['id']);
check('pressing the fix again shows the same bills fixed and none waiting for a split it already did', $billsOf($jm->reconcileJob($job['id'])['results']) === $billsOf($linesAfter));
$jm->deleteAsnRecords($job['id'], array_column($jm->getAsnRecords($job['id']), 'id'));
$jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
$reimported = $jm->reconcileJob($job['id'])['results'];
check(
    'clearing the ASN, importing it again and comparing shows the same bills fixed and none waiting for a split already done',
    $billsOf($reimported) === $billsOf($linesAfter),
    json_encode($billsOf($reimported), JSON_UNESCAPED_UNICODE)
);
$pdo->exec("DELETE FROM job_invoice_fixes WHERE job_id = {$job['id']} AND invoice_no = '93247359-3'");
$withStepsOnly = AsnReconciler::invoiceStatuses($jm->reconcileJob($job['id'])['results'])['93247359-3'];
$pdo->exec("DELETE FROM job_invoice_fix_steps WHERE job_id = {$job['id']} AND invoice_no = '93247359-3'");
$withNeither = AsnReconciler::invoiceStatuses($jm->reconcileJob($job['id'])['results'])['93247359-3'];
check('the logged split alone keeps a bill out of waiting; without it and without a fix record the bill waits for its split again', [$withStepsOnly, $withNeither] === ['MATCH', 'PENDING_FIX'], json_encode([$withStepsOnly, $withNeither]));

$gapJob = $jm->createJob('Page gaps');
$insertStep = $pdo->prepare('INSERT INTO job_invoice_fix_steps (job_id, invoice_no, kind, filename, page_no, from_value) VALUES (?, ?, ?, ?, ?, ?)');
foreach ([
    ['9500001-1', 'renumber', 'g.pdf', 2, '9500001-2'], ['9500001-1', 'clear_footer', 'g.pdf', 1, ''], ['9500001-1', 'clear_footer', 'g.pdf', 3, ''],
    ['9500001-1', 'split', 'g.pdf', 1, ''], ['9500001-1', 'split', 'g.pdf', 2, ''], ['9500001-1', 'split', 'g.pdf', 3, ''], ['9500001-1', 'split', 'g.pdf', 5, ''],
    ['9500002-1', 'split', 'h.pdf', 1, ''], ['9500002-1', 'split', 'h.pdf', 2, ''], ['9500002-1', 'split', 'i.pdf', 4, ''],
] as $s) {
    $insertStep->execute(array_merge([$gapJob['id']], $s));
}
$gapTexts = (new FixLog($pdo))->describe($gapJob['id'], ['9500001-1', '9500001-2', '9500002-1'], []);
check(
    'a remark separates its topics with a comma and joins the pages of one topic with "และ", a gap and a second file included',
    $gapTexts === [
        '9500001-1' => 'แก้เลขบิลจากหน้า 2 (9500001-2 เป็น 9500001-1), ลบยอดรวมท้ายบิลจากหน้า 1 และ 3, แยกออกมาจากหน้า 1-3 และ 5',
        '9500001-2' => 'หน้า 2 ถูกย้ายไปเป็น 9500001-1',
        '9500002-1' => 'แยกออกมาจากหน้า 1-2 ของ h.pdf และ หน้า 4 ของ i.pdf',
    ],
    json_encode($gapTexts, JSON_UNESCAPED_UNICODE)
);

$singleSample = __DIR__ . '/fixtures/CDS ZFP (ZKE)/RM Document-Customer Billing(PDF)-0093250621 3.pdf';
if (is_file($singleSample)) {
    $job = $jm->createJob('Single invoice');
    $finished = $jm->getJobFinishedDir($job);
    copy($singleSample, $finished . '/single.pdf');
    $rows = (new PdfExtractor())->extractDetailedRows($finished . '/single.pdf');
    $entries = [['filename' => 'single.pdf', 'storedPath' => $finished . '/single.pdf', 'items' => $rows]];
    $jm->recordExtractedBatch($job['id'], $entries, true);
    $footer = [0.0, 0.0];
    foreach ($rows as $r) {
        if (!in_array($r['amt_excl_vat'], ['Null', '-', ''], true)) {
            $footer = [(float)str_replace(',', '', $r['amt_excl_vat']), (float)str_replace(',', '', $r['vat_amount'])];
        }
    }
    $asn = [];
    foreach ($rows as $r) {
        $asn[] = [
            'invoice_no' => '93250621-1', 'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '',
            'selling_price' => 1, 'total_cost_ex_vat' => $footer[0], 'total_vat' => $footer[1],
        ];
    }
    $jm->saveAsnRecords($job['id'], 't.xlsx', $asn);
    $jm->reconcileJob($job['id']);
    (new PdfFixer($pdo))->fixJob($job['id']);
    $row = dashboardRows($jm, $job['id'])['93250621-1'] ?? [];
    check(
        'a file whose only invoice needs nothing is left alone: no fix record, no step, no split file, shown complete',
        fixRecords($pdo, $job['id']) === [] && fixSteps($pdo, $job['id']) === [] && !is_file($finished . '/93250621-1.pdf')
            && ($row['status'] ?? '') === 'MATCH' && ($row['fixed'] ?? null) === false && ($row['note'] ?? 'x') === '' && ($row['pages'] ?? 0) === 3,
        json_encode($row, JSON_UNESCAPED_UNICODE)
    );
}

check('a new database starts at schema version 9', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 9);
$pdo->exec('DROP TABLE job_invoice_fix_steps');
$pdo->exec('PRAGMA user_version = 6');
Database::migrateSchema($pdo);
check(
    'a version 6 database gains the fix step table and moves to version 9',
    (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'job_invoice_fix_steps'")->fetchColumn() === 1
        && (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 9
);

$stepJob = $jm->createJob('Toolbar step');
$stepRow = $jm->getJob($stepJob['id']);
$asn = ['asn_filename' => 'ASN.xlsx', 'asn_reconciled' => 1, 'asn_pending_fix_count' => 0, 'asn_matched_count' => 5];
check(
    'the ASN toolbar step is picked from the stored job state, so the page loads with the right button highlighted (a Job with ASN rows and no VBRP row waits for its VBRP file)',
    $jm->initialAsnStep($stepRow) === 'select'
        && $jm->initialAsnStep(['asn_filename' => 'ASN.xlsx'] + $stepRow) === 'vbrp'
        && $jm->initialAsnStep(['asn_pending_fix_count' => 3] + $asn + $stepRow) === 'fix'
        && $jm->initialAsnStep(['asn_matched_count' => 0] + $asn + $stepRow) === 'done'
        && $jm->initialAsnStep($asn + $stepRow) === 'print'
);
$round = $jm->getJobPrintDir($stepRow) . '/print ครั้งที่ 1/10114';
mkdir($round, 0777, true);
file_put_contents($round . '/1_93250621-1.pdf', '%PDF');
check('a job that already has a print round is shown as done, not as waiting to print', $jm->initialAsnStep($asn + $stepRow) === 'done');

$page = renderWorkspace(['job_id' => $stepJob['id'], 'tab' => 'asn']);
check(
    'workspace.php renders that step and the matching button states into the HTML',
    strpos($page, 'id="view-asn-section" data-step="select"') !== false
        && preg_match('/id="btn-print" disabled/', $page) === 1
        && preg_match('/id="btn-export-recon" disabled/', $page) === 1
        && preg_match('/id="view-pdf-section" data-step="idle"/', $page) === 1
);
check('the clear-data modal says that clearing the ASN does not undo the fix', strpos($page, 'การล้างข้อมูล ASN ไม่ย้อนการแก้ไขที่ทำกับ PDF แล้ว ถ้าต้องการเริ่มแก้ไขใหม่ ให้ล้างข้อมูล PDF แล้วดึงข้อมูลใหม่') !== false);
preg_match('/id="asn-table">.*?<\/thead>/s', $page, $asnHead);
preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $asnHead[0] ?? '', $asnHeaders);
$asnHeaders = array_map(function ($h) {
    return trim(strip_tags($h));
}, $asnHeaders[1]);
$asnOrder = ['#', 'Status', 'Tax Invoice No.', 'Print', 'Source File', 'Barcode / EAN', 'Description', 'Store (PDF)', 'Store (ASN)', 'Qty (PDF)', 'Qty (ASN)', 'Qty (VBRP)',
    'RSP Price (PDF)', 'Sell Price (ASN)', 'Sell Price (VBRP)', 'Amt Excl VAT (PDF)', 'Cost Ex VAT (ASN)', 'Amt Excl VAT (VBRP)', 'VAT (PDF / ASN)', 'VAT (VBRP)',
    'PDF', 'ASN', 'VBRP', 'Remark', 'Manage'];
check(
    'the ASN grid has its columns in the new order, # Status and Tax Invoice No. first, one Remark column in place of Discrepancy and Action, every header English',
    $asnHeaders === $asnOrder && !preg_grep('/[^\x20-\x7e]/', $asnHeaders) && strpos($asnHead[0], 'Discrepancy') === false,
    json_encode($asnHeaders, JSON_UNESCAPED_UNICODE)
);
preg_match('/const ASN_COLUMNS = (.*?);\n/', $page, $columnsJson);
preg_match('/id="asn-tbody">\s*<tr class="placeholder-grid-row">.*?<\/tr>/s', $page, $placeholder);
check(
    'the columns the scripts build cells, sort and Excel from are the ones of the header, and an empty grid shows blank rows of that many cells',
    array_column(json_decode($columnsJson[1] ?? '[]', true) ?: [], 'label') === $asnOrder && substr_count($placeholder[0] ?? '', '<td') === count($asnOrder),
    ($columnsJson[1] ?? 'no ASN_COLUMNS') . ' / ' . substr_count($placeholder[0] ?? '', '<td')
);

// Rows on more stores than the sample has: store numbers and invoice numbers that sort apart as text and as numbers,
// and a bill without a store
foreach ([['10021', '93000001-10'], [null, '93000000-1'], ['10021', '93000001-2'], ['9001', '93000002-1']] as [$store, $invoice]) {
    $exportDash[] = ['store_no' => $store, 'invoice_no' => $invoice] + $exportDash[0];
}
$dashOrder = array_keys($exportDash);
usort($dashOrder, function ($a, $b) use ($exportDash) {
    [$x, $y] = [(string)$exportDash[$a]['store_no'], (string)$exportDash[$b]['store_no']];
    return (($x === '') <=> ($y === '')) ?: (strnatcmp($x, $y) ?: (strnatcmp($exportDash[$a]['invoice_no'], $exportDash[$b]['invoice_no']) ?: $a <=> $b));
});

if (trim((string)shell_exec('node --version')) === '') {
    echo "SKIP  the Excel files of the ASN page and the dashboard (node is not on PATH)\n";
} else {
    $exportFiles = [];
    foreach (['page' => $page, 'lines' => json_encode($exportLines), 'dash' => json_encode($exportDash)] as $name => $content) {
        $exportFiles[$name] = tempnam(sys_get_temp_dir(), 'excel_' . $name . '_');
        file_put_contents($exportFiles[$name], $content);
    }
    $sheets = json_decode((string)shell_exec('node ' . implode(' ', array_map('escapeshellarg', array_merge([__DIR__ . '/excel_columns.js'], array_values($exportFiles))))), true);
    array_map('unlink', $exportFiles);

    $expected = [];
    foreach ($exportLines as $r) {
        $fixed = $r['status'] === 'MATCH' && $r['fixed'];
        $expected[] = [
            $r['pdf']['tax_invoice'] ?? $r['asn']['invoice_no'],
            $fixed ? '' : $r['reason'],
            $fixed ? $r['fix_note'] : ($r['status'] === 'MATCH' ? '' : $r['action']),
        ];
    }
    $exported = array_map(function ($row) {
        return [$row['Tax Invoice No.'], $row['Problem'], $row['Solution']];
    }, $sheets['asn'] ?? []);
    $differ = array_keys(array_filter(array_map(function ($a, $b) {
        return $a !== $b;
    }, $exported + array_fill(0, count($expected), null), $expected)));
    check(
        'the Excel file of the ASN page has the columns of the grid in its order, Previous status after Status, Problem and Solution in place of the Remark, without the labels',
        array_keys($sheets['asn'][0] ?? []) === ['#', 'Status', 'Previous status', 'Tax Invoice No.', 'Print', 'Source File', 'Barcode / EAN', 'Description', 'Store (PDF)', 'Store (ASN)',
            'Qty (PDF)', 'Qty (ASN)', 'Qty (VBRP)', 'RSP Price (PDF)', 'Sell Price (ASN)', 'Sell Price (VBRP)', 'Amt Excl VAT (PDF)', 'Cost Ex VAT (ASN)', 'Amt Excl VAT (VBRP)',
            'VAT (PDF)', 'VAT (ASN)', 'VAT (VBRP)', 'PDF', 'ASN', 'VBRP', 'Problem', 'Solution']
            && count($exported) === count($expected) && $differ === [] && count(array_unique(array_column($expected, 2))) > 3,
        json_encode([array_keys($sheets['asn'][0] ?? []), count($exported), array_map(function ($i) use ($exported, $expected) {
            return [$exported[$i] ?? null, $expected[$i]];
        }, array_slice($differ, 0, 2))], JSON_UNESCAPED_UNICODE)
    );
    $remarks = array_map(function ($i) use ($exportDash) {
        $row = $exportDash[$i];
        $lines = array_filter([$row['note'], $row['problem'] !== '' ? 'ปัญหา : ' . $row['problem'] : '', $row['solution'] !== '' ? 'วิธีแก้ไข : ' . $row['solution'] : ''], 'strlen');
        return [$row['store_no'], $row['invoice_no'], $row['total_cost_ex_vat'], $lines ? implode("\n", $lines) : null];
    }, $dashOrder);
    $exportedRemarks = array_map(function ($row) {
        return [$row['Dest Store No.'], $row['Tax Invoice No.'], $row['Total Cost Ex VAT'] === null ? null : (float)$row['Total Cost Ex VAT'], $row['Remark']];
    }, $sheets['dashboard'] ?? []);
    check(
        'the Excel file of the dashboard has its columns, Branch Code kept and Total Cost Ex VAT in place of the net amount, its rows in store then invoice order, '
            . 'and its Remark holds what the fix did, then "ปัญหา : " and "วิธีแก้ไข : " on lines of their own',
        array_keys($sheets['dashboard'][0] ?? []) === ['#', 'Dest Store No.', 'Branch Code', 'Tax Invoice No.', 'Source File', 'Total Qty', 'Total Cost Ex VAT', 'Amt Excl VAT (VBRP)', 'Pages', 'Status', 'Previous status', 'PDF', 'ASN', 'VBRP', 'Remark']
            && $exportedRemarks === $remarks && count(array_filter(array_column($remarks, 3), function ($r) {
                return substr_count((string)$r, "\n") >= 1;
            })) > 0 && array_column($remarks, 1)[0] === '93000002-1' && end($remarks)[0] === null,
        json_encode([array_keys($sheets['dashboard'][0] ?? []), $exportedRemarks, $remarks], JSON_UNESCAPED_UNICODE)
    );
    $tone = ['MATCH' => 'remark-match', 'FIXED' => 'remark-fixed', 'PENDING_FIX' => 'remark-pending-fix', 'MISMATCH' => 'remark-mismatch'];
    $remarkShape = function (string $html): array {
        preg_match_all('/<div class="([^"]*)">(?:<span class="remark-label">([^<]*)<\/span>)?/u', $html, $m, PREG_SET_ORDER);
        return array_map(function ($line) {
            return [$line[1], $line[2] ?? ''];
        }, $m);
    };
    $badRemarks = [];
    $shown = [];
    foreach ($exportLines as $i => $r) {
        $key = $r['status'] === 'MATCH' && $r['fixed'] ? 'FIXED' : $r['status'];
        $want = [
            'MATCH' => [[$tone['MATCH'], '']],
            'FIXED' => [[$tone['FIXED'], '']],
            'PENDING_FIX' => [[$tone['PENDING_FIX'], 'ปัญหา : '], [$tone['PENDING_FIX'], 'วิธีแก้ไข : ']],
            'MISMATCH' => [[$tone['MISMATCH'], 'ปัญหา : ']],
        ][$key];
        $shown['asn ' . $key] = true;
        if ($remarkShape($sheets['remarks']['asn'][$i] ?? '') !== $want) {
            $badRemarks[] = ['asn', $key, $sheets['remarks']['asn'][$i] ?? ''];
        }
    }
    foreach ($exportDash as $i => $row) {
        $key = $row['status'] === 'MATCH' && $row['fixed'] ? 'FIXED' : $row['status'];
        $want = array_merge(
            $row['note'] !== '' ? [[$tone['FIXED'], '']] : [],
            $row['problem'] !== '' ? [[$tone[$key], 'ปัญหา : ']] : [],
            $row['solution'] !== '' ? [[$tone[$key], 'วิธีแก้ไข : ']] : []
        );
        $shown['dashboard ' . $key . ($row['note'] !== '' ? ' after a fix' : '')] = true;
        if ($remarkShape($sheets['remarks']['dashboard'][$i] ?? '') !== $want || ($key === 'MISMATCH' && $row['solution'] !== '')) {
            $badRemarks[] = ['dashboard', $row['invoice_no'], $key, $sheets['remarks']['dashboard'][$i] ?? ''];
        }
    }
    ksort($shown);
    check(
        'every Remark line, label included, is in the colour of its status in both tables: complete green, fixed blue, waiting amber, wrong red with one line "ปัญหา : " and no solution; on the dashboard what the fix did comes first in blue, then the red problem of a bill still wrong',
        $badRemarks === [] && array_keys($shown) === ['asn FIXED', 'asn MATCH', 'asn MISMATCH', 'asn PENDING_FIX', 'dashboard FIXED after a fix', 'dashboard MISMATCH', 'dashboard MISMATCH after a fix', 'dashboard PENDING_FIX'],
        json_encode([array_keys($shown), array_slice($badRemarks, 0, 3)], JSON_UNESCAPED_UNICODE)
    );
}

if (trim((string)shell_exec('node --version')) === '') {
    echo "SKIP  the grid, dashboard and toolbar with three sources (node is not on PATH)\n";
} else {
    $viewPage = tempnam(sys_get_temp_dir(), 'vbrp_view_page_');
    file_put_contents($viewPage, $page);
    $viewChecks = json_decode((string)shell_exec('node ' . escapeshellarg(__DIR__ . '/vbrp_view.js') . ' ' . escapeshellarg($viewPage)), true) ?: [];
    unlink($viewPage);
    check('tests/vbrp_view.js ran all 17 of its checks on the page scripts', count($viewChecks) === 17, (string)count($viewChecks));
    foreach ($viewChecks as $viewCheck) {
        check($viewCheck['name'], $viewCheck['ok'], $viewCheck['detail']);
    }
}

if (trim((string)shell_exec('node --version')) === '') {
    echo "SKIP  the grid and the dashboard with รอแก้ไข PDF, รอแก้ไข ASN and the earlier statuses of a bill (node is not on PATH)\n";
} else {
    $splitPage = tempnam(sys_get_temp_dir(), 'status_split_page_');
    file_put_contents($splitPage, $page);
    $splitChecks = json_decode((string)shell_exec('node ' . escapeshellarg(__DIR__ . '/status_split_view.js') . ' ' . escapeshellarg($splitPage)), true) ?: [];
    unlink($splitPage);
    check('tests/status_split_view.js ran all 26 of its checks on the page scripts', count($splitChecks) === 26, (string)count($splitChecks));
    foreach ($splitChecks as $splitCheck) {
        check($splitCheck['name'], $splitCheck['ok'], $splitCheck['detail']);
    }
}

check(
    'the script that colours a column from a right-click on its header is loaded after the dashboard script',
    preg_match('/workspace\/dashboard\.js\?v=\d+"><\/script>\s*<script src="assets\/js\/workspace\/column-highlight\.js\?v=\d+"/', $page) === 1
);
if (trim((string)shell_exec('node --version')) === '') {
    echo "SKIP  the column colours (node is not on PATH)\n";
} else {
    $highlightPage = tempnam(sys_get_temp_dir(), 'highlight_page_');
    file_put_contents($highlightPage, $page);
    $hl = json_decode((string)shell_exec('node ' . escapeshellarg(__DIR__ . '/column_highlight.js') . ' ' . escapeshellarg($highlightPage)), true) ?: [];
    unlink($highlightPage);

    $headerKeys = $hl['headers'] ?? [];
    $keyFaults = [];
    foreach (['detailed-table', 'asn-table', 'dash-table'] as $tableId) {
        $keys = $headerKeys[$tableId] ?? [];
        if (!$keys || in_array('', $keys, true) || count(array_unique($keys)) !== count($keys)) {
            $keyFaults[] = $tableId;
        }
    }
    check(
        'every header of the three tables names its column (data-col) with a key no other header of that table has, and the ASN keys are the ones of ASN_COLUMNS, so a column is found by its key',
        $keyFaults === [] && ($headerKeys['asn-table'] ?? null) === array_column(json_decode($columnsJson[1] ?? '[]', true) ?: [], 'key'),
        json_encode($keyFaults)
    );

    $position = function (string $tableId, string $key) use ($headerKeys): int {
        return array_search($key, $headerKeys[$tableId] ?? [], true) + 1;
    };
    $paints = function (string $css, string $tableId, string $key, string $color) use ($position): bool {
        $n = $position($tableId, $key);
        return strpos($css, "#$tableId tbody td:nth-child($n):not([colspan]) { background-color: $color !important; }") !== false
            && strpos($css, "#$tableId thead th:nth-child($n) { background-color: $color !important; box-shadow: inset 0 -3px 0 rgba(15, 23, 42, 0.22); }") !== false
            && strpos($css, "#$tableId thead th:nth-child($n):hover { background-image:") !== false;
    };
    $chosen = ['detailed-table' => ['tax_invoice' => '#b2f2bb'], 'asn-table' => ['barcode' => '#fff59d'], 'dash-table' => ['status' => '#a5d8ff'], 'credit-note-table' => [], 'credit-note-asn-table' => [], 'credit-note-dash-table' => [], 'vbrp-table' => []];
    check(
        'a colour chosen for a header fills the header cell (with a darker bottom edge, a hover tint) and every body cell of that column by its position in the header row, in each of the three tables, and is kept (lower case) for the next visit',
        ($hl['cssAtStart'] ?? 'x') === ''
            && $paints($hl['css'] ?? '', 'detailed-table', 'tax_invoice', '#b2f2bb')
            && $paints($hl['css'] ?? '', 'asn-table', 'barcode', '#fff59d')
            && $paints($hl['css'] ?? '', 'dash-table', 'status', '#a5d8ff')
            && substr_count($hl['css'] ?? '', '!important') === 6 && strpos($hl['css'] ?? '', '#ffffff') === false
            && ($hl['stored'] ?? null) === $chosen && ($hl['reloaded'] ?? null) === $chosen,
        json_encode([$position('asn-table', 'barcode'), $hl['stored'] ?? null])
    );
    $darkCss = $hl['dark']['css'] ?? '';
    $darkAt = $position('asn-table', 'status');
    check(
        'a dark colour turns the text of its header and cells white (the status badges keep their own colours) and a light one never does, so both stay readable',
        strpos($darkCss, "#asn-table thead th:nth-child($darkAt) { background-color: #1e3a8a !important; box-shadow: inset 0 -3px 0 rgba(15, 23, 42, 0.22); color: #ffffff !important; }") !== false
            && strpos($darkCss, "#asn-table thead th:nth-child($darkAt) .sort-icon { color: #ffffff; }") !== false
            && strpos($darkCss, "#asn-table tbody td:nth-child($darkAt):not([colspan]) :not(.pill-badge, .pill-badge *) { color: #ffffff !important; }") !== false
            && ($hl['dark']['dark'] ?? null) === ['#000000' => true, '#1e3a8a' => true, '#fff59d' => false, '#ffffff' => false, '#808080' => false, '#ff0000' => false],
        json_encode($hl['dark']['dark'] ?? null)
    );
    check(
        'taking the colour off one column, or off a whole table, leaves the other columns and tables painted',
        ($hl['afterClearOne']['asn'] ?? 'x') === [] && strpos($hl['afterClearOne']['css'] ?? '', '#asn-table') === false
            && $paints($hl['afterClearOne']['css'] ?? '', 'detailed-table', 'tax_invoice', '#b2f2bb')
            && $paints($hl['afterClearOne']['css'] ?? '', 'dash-table', 'status', '#a5d8ff')
            && ($hl['afterClearTable']['state']['dash-table'] ?? 'x') === [] && strpos($hl['afterClearTable']['css'] ?? '', '#dash-table') === false
            && $paints($hl['afterClearTable']['css'] ?? '', 'detailed-table', 'tax_invoice', '#b2f2bb'),
        json_encode($hl['afterClearTable'] ?? null)
    );
    $noColours = ['detailed-table' => [], 'asn-table' => [], 'dash-table' => [], 'credit-note-table' => [], 'credit-note-asn-table' => [], 'credit-note-dash-table' => [], 'vbrp-table' => []];
    check(
        'saved colours are read back only when they are #rrggbb colours of a known table: text that could break out of a style rule, short codes, numbers, unknown tables, lists and a saved list are dropped',
        ($hl['hostile'] ?? null) === ['detailed-table' => [], 'asn-table' => ['status' => '#b2f2bb'], 'dash-table' => [], 'credit-note-table' => [], 'credit-note-asn-table' => [], 'credit-note-dash-table' => [], 'vbrp-table' => []]
            && ($hl['topLevelArray'] ?? null) === $noColours,
        json_encode([$hl['hostile'] ?? null, $hl['topLevelArray'] ?? null])
    );
    check(
        'saved colours that are not JSON, or a browser that blocks its storage, give a table with no colours and no error, and a colour chosen then still paints the column for that visit',
        ($hl['brokenJson'] ?? null) === $noColours && ($hl['storageBlocked'] ?? null) === $noColours
            && $paints($hl['cssWithStorageBlocked'] ?? '', 'asn-table', 'remark', '#ffd8a8'),
        json_encode([$hl['brokenJson'] ?? null, $hl['storageBlocked'] ?? null])
    );
}

$css = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
$stateJs = (string)file_get_contents(__DIR__ . '/../assets/js/workspace/state.js');
preg_match_all("/tone: '([a-z-]+)'/", $stateJs, $tones);
$colourFaults = [];
foreach ($tones[1] as $t) {
    preg_match_all('/--status-' . $t . '-color:\s*(#[0-9a-f]{6});/i', $css, $defined);
    foreach (['.status-' . $t . ' {', '.remark-' . $t . ' {'] as $selector) {
        $start = strpos($css, $selector);
        $rule = $start === false ? '' : substr($css, $start, strpos($css, '}', $start) - $start);
        if (strpos($rule, 'color: var(--status-' . $t . '-color)') === false) {
            $colourFaults[] = "$selector does not read --status-$t-color";
        }
    }
    if (count($defined[1]) !== 1) {
        $colourFaults[] = "--status-$t-color is defined " . count($defined[1]) . ' times';
    }
}
foreach (['MATCH' => 'match', 'FIXED' => 'fixed', 'PENDING_FIX' => 'pending-fix', 'PENDING_ASN' => 'pending-asn', 'MISMATCH' => 'mismatch'] as $filter => $t) {
    if (!preg_match('/\.recon-filter-btn\[data-filter="' . $filter . '"\]\.active \{[^}]*color: var\(--status-' . $t . '-color\);/', $css)) {
        $colourFaults[] = "the $filter filter chip does not read --status-$t-color";
    }
}
check(
    'each status colour is defined once in app.css and the badge, the active filter chip and the Remark text of that status read it; state.js names the tones and holds no colour',
    count($tones[1]) === 6 && $colourFaults === [] && !preg_match('/#[0-9a-f]{3,6}\b/i', $stateJs),
    json_encode([$tones[1], $colourFaults])
);
preg_match('/id="dash-table">.*?<\/thead>/s', $page, $dashHead);
preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $dashHead[0] ?? '', $dashHeaders);
$dashHeaders = array_map(function ($h) {
    return preg_replace('/\s+/', ' ', trim(strip_tags($h)));
}, $dashHeaders[1]);
check(
    'the dashboard table keeps its exact header texts, Total Cost Ex VAT in place of Net Amount (Incl VAT), Previous status after Status and the PDF, ASN, VBRP verdicts before Remark, and opens with the store column marked as sorted ascending',
    $dashHeaders === ['#', 'Dest Store No.', 'Tax Invoice No.', 'Source File', 'Total Qty', 'Total Cost Ex VAT', 'Pages', 'Status', 'Previous status', 'PDF', 'ASN', 'VBRP', 'Remark']
        && preg_match_all('/<th\b[^>]*\bclass="[^"]*\bsorted\b[^>]*>(.*?)<\/th>/s', $dashHead[0] ?? '', $sortedHeads) === 1
        && trim(strip_tags($sortedHeads[1][0])) === 'Dest Store No.' && strpos($sortedHeads[1][0], 'fa-sort-up') !== false,
    json_encode($dashHeaders)
);

$pdo->prepare("UPDATE jobs SET asn_filename = 'ASN.xlsx', asn_reconciled = 1, asn_pending_fix_count = 7, asn_matched_count = 5 WHERE id = :id")->execute([':id' => $stepJob['id']]);
$page = renderWorkspace(['job_id' => $stepJob['id'], 'tab' => 'asn']);
check(
    'a job with rows waiting for a fix loads with the fix step, the fix button and its count already shown',
    strpos($page, 'id="view-asn-section" data-step="fix"') !== false
        && preg_match('/id="btn-fix-all-pending" style="display: inline-flex; align-items: center;"/', $page) === 1
        && preg_match('/id="btn-fix-all-count">7</', $page) === 1
        && preg_match('/id="btn-export-recon" onclick/', $page) === 1
);

$pdo->exec("INSERT INTO jobs (name, status, created_at, updated_at) VALUES ('Made before the split', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$legacyId = (int)$pdo->lastInsertId();
$legacyDir = $root . '/myfile/Made before the split';
$second = dirname($sample) . '/RM Document-Customer Billing(PDF)-0093248051 3.pdf';
mkdir($legacyDir . '/upload here/sub', 0777, true);
mkdir($legacyDir . '/finished');
mkdir($legacyDir . '/print');
mkdir($legacyDir . '/credit note/upload here', 0777, true);
copy($sample, $legacyDir . '/upload here/sub/multi.pdf');
copy($second, $legacyDir . '/upload here/second.pdf');
file_put_contents($legacyDir . '/credit note/upload here/cn.pdf', 'credit note bytes');
file_put_contents($legacyDir . '/finished/open elsewhere.txt', 'held open');
$legacyWork = $root . '/upload/Made before the split';
$windows = DIRECTORY_SEPARATOR === '\\';
$handle = $windows ? fopen($legacyDir . '/finished/open elsewhere.txt', 'r') : null;
$extracted = api('extract_job_batch', ['job_id' => $legacyId, 'files' => json_encode([['filename' => 'multi.pdf', 'rel_path' => 'sub/multi.pdf'], ['filename' => 'second.pdf', 'rel_path' => 'second.pdf']])]);
if ($windows) {
    check(
        'while the old finished folder of a Job made before the split cannot be moved (a file inside is open), extraction still reads the waiting PDFs from upload/<Job>/invoice/pdf, where the first request moved them, and copies them into that old folder',
        ($extracted['status'] ?? '') === 'success' && ($extracted['processed_files'] ?? 0) === 2 && is_file($legacyDir . '/finished/multi.pdf')
            && is_file($legacyDir . '/finished/second.pdf') && !is_dir($legacyDir . '/TAX INVOICE/finished') && !is_dir($legacyDir . '/upload here')
            && is_file($legacyWork . '/invoice/pdf/sub/multi.pdf') && is_file($legacyWork . '/invoice/pdf/second.pdf')
            && !is_dir($legacyDir . '/TAX INVOICE/upload here') && is_file($legacyWork . '/cn/pdf/cn.pdf'),
        json_encode([$extracted['status'] ?? $extracted, $extracted['processed_files'] ?? null, array_values(array_diff(scandir($legacyDir), ['.', '..']))], JSON_UNESCAPED_UNICODE)
    );
    fclose($handle);
} else {
    echo "SKIP  extraction into an old finished folder Windows refuses to move (holding a file open blocks a rename on Windows only)\n";
}
api('scan_job_files', [], ['job_id' => $legacyId]);
$tiDir = $legacyDir . '/TAX INVOICE';
$storedPath = (string)$pdo->query("SELECT file_path FROM job_files WHERE job_id = {$legacyId} AND filename = 'multi.pdf'")->fetchColumn();
check(
    'the next request moves that finished folder into TAX INVOICE with the extracted PDFs; job_files.file_path still names the old place',
    array_values(array_diff(scandir($legacyDir), ['.', '..'])) === ['CREDIT NOTE', 'TAX INVOICE'] && is_file($tiDir . '/finished/multi.pdf')
        && is_file($tiDir . '/finished/second.pdf') && is_file($tiDir . '/finished/open elsewhere.txt') && $storedPath !== '' && !is_file($storedPath),
    $storedPath
);

mkdir($root . '/elsewhere');
copy($sample, $root . '/elsewhere/multi.pdf');
copy($second, $root . '/elsewhere/second.pdf');
$pdo->prepare('UPDATE job_files SET file_path = ? WHERE job_id = ? AND filename = ?')->execute([$root . '/elsewhere/multi.pdf', $legacyId, 'multi.pdf']);
$pdo->prepare('UPDATE job_files SET file_path = ? WHERE job_id = ? AND filename = ?')->execute([$root . '/elsewhere/second.pdf', $legacyId, 'second.pdf']);
$asn = [];
foreach ($jm->getJobItems($legacyId) as $r) {
    if ($r['filename'] !== 'multi.pdf') {
        continue;
    }
    $page = (int)$r['page_no'];
    $asn[] = [
        'invoice_no' => $page <= 2 ? '93247359-1' : ($page === 3 ? '93247359-2' : '93247359-3'),
        'ean_upc' => $r['barcode'], 'quantity' => $r['units'], 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1,
    ];
}
$jm->saveAsnRecords($legacyId, 't.xlsx', $asn);
(new PdfFixer($pdo))->fixJob($legacyId);
check(
    'after the move the fix reads the master PDF from TAX INVOICE/finished, even when job_files.file_path names another copy that exists, and writes the split files there',
    count(glob($tiDir . '/finished/93247359-*.pdf')) === 3 && count(glob($root . '/elsewhere/93247359-*.pdf')) === 0,
    json_encode([glob($tiDir . '/finished/*.pdf'), glob($root . '/elsewhere/*')], JSON_UNESCAPED_UNICODE)
);
foreach ($jm->getJobItems($legacyId) as $it) {
    if ($it['filename'] === 'multi.pdf' && !in_array($it['amt_excl_vat'], ['Null', '-', ''], true)) {
        $pdo->prepare('UPDATE job_asn_records SET total_cost_ex_vat = ?, total_vat = ? WHERE job_id = ? AND invoice_no = ?')
            ->execute([(float)str_replace(',', '', $it['amt_excl_vat']), (float)str_replace(',', '', $it['vat_amount']), $legacyId, $it['tax_invoice']]);
    }
}
$printed = (new PrintManager($jm))->runAll($legacyId);
$roundFiles = is_dir($tiDir . '/print/print ครั้งที่ 1/10166') ? array_values(array_diff(scandir($tiDir . '/print/print ครั้งที่ 1/10166'), ['.', '..'])) : [];
check(
    'printing the moved Job builds its round in TAX INVOICE/print and names that folder in the summary',
    $roundFiles === ['1_93247359-1.pdf', '2_93247359-2.pdf', '3_93247359-3.pdf'] && ($printed['folder'] ?? '') === 'myfile/Made before the split/TAX INVOICE/print/print ครั้งที่ 1',
    json_encode([$printed, $roundFiles], JSON_UNESCAPED_UNICODE)
);
$secondIds = array_column(array_filter($jm->getJobItems($legacyId), function ($it) {
    return $it['filename'] === 'second.pdf';
}), 'id');
$deletedItems = api('delete_job_items', ['job_id' => $legacyId, 'ids' => json_encode($secondIds)]);
check(
    'deleting every row of one file removes its PDF from TAX INVOICE/finished and never the file job_files.file_path names; the other file stays',
    ($deletedItems['deleted_count'] ?? 0) === count($secondIds) && count($secondIds) > 0 && !is_file($tiDir . '/finished/second.pdf')
        && is_file($root . '/elsewhere/second.pdf') && is_file($tiDir . '/finished/multi.pdf'),
    json_encode([$deletedItems['deleted_count'] ?? $deletedItems, count($secondIds)])
);
api('clear_job', ['job_id' => $legacyId]);
check(
    'clear_job on the moved Job deletes the processed PDFs of TAX INVOICE/finished and keeps upload/<Job>/ and the printed round',
    FileManager::getPdfFiles($tiDir . '/finished') === [] && is_file($legacyWork . '/cn/pdf/cn.pdf') && is_file($legacyWork . '/invoice/pdf/second.pdf') && count($roundFiles) === 3
        && is_file($tiDir . '/print/print ครั้งที่ 1/10166/1_93247359-1.pdf')
);
api('delete_job', ['job_id' => $legacyId, 'confirm_name' => 'Made before the split']);
check('deleting the moved Job removes its whole folder in myfile/ and keeps upload/<Job>/', !is_dir($legacyDir) && $jm->getJob($legacyId) === null && is_file($legacyWork . '/invoice/pdf/sub/multi.pdf'));

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
