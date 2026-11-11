<?php
// Run: php tests/credit_note_compare_test.php
// The credit note compare and dashboard (job_cn_import_asn ... job_cn_dashboard) on small made-up credit notes, plus
// what the reading stores for three real credit notes of tests/fixtures/cn. Runs against a throwaway root
// (PDF_READER_ROOT), never touches data/ or myfile/.

$mode = $argv[1] ?? '';

if ($mode === '--api') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = ['action' => $request['action']] + $request['get'];
    $_POST = $request['post'];
    require __DIR__ . '/../api.php';
    exit;
}

const STD = '5048_93251044-1_DN.issue.at_20261016_5048-ROBINSON BOWIN 20186.pdf';
const TWO = 'RM Document-Customer Billing(PDF)-0093247387 3.pdf';
const ATTACHMENT = 'RM Document-Customer Billing(PDF)-0093260445.pdf';

if ($mode !== '--run') {
    foreach ([STD, TWO, ATTACHMENT] as $name) {
        if (!is_file(__DIR__ . '/fixtures/cn/' . $name)) {
            fwrite(STDERR, "SKIP: credit note sample not found: tests/fixtures/cn/$name\n");
            exit(2);
        }
    }
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_cn_compare_' . bin2hex(random_bytes(4));
    mkdir($root . '/data', 0777, true);
    mkdir($root . '/myfile', 0777, true);
    putenv('PDF_READER_ROOT=' . $root);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run', $code);
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($tree as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
    exit($code);
}

$root = getenv('PDF_READER_ROOT');

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\JobManager;

$jm = new JobManager();
$pdo = Database::getConnection();
$failures = 0;

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
    $requestFile = tempnam(sys_get_temp_dir(), 'cnc_req_');
    file_put_contents($requestFile, json_encode(['action' => $action, 'post' => $post, 'get' => $get]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($requestFile));
    unlink($requestFile);
    return json_decode($out, true) ?: ['unparsed' => $out];
}

function show($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

/** The answer has the key and it is null (?? cannot tell a null from a missing key) */
function nullAt(array $answer, string $key): bool
{
    return array_key_exists($key, $answer) && $answer[$key] === null;
}

/**
 * A credit note document as CreditNoteStore stores what CreditNoteReader read, written straight into the tables;
 * $items are [barcode, units, unit price, amount]
 */
function pdfDoc(int $jobId, string $docNo, array $fields, array $items): void
{
    global $pdo;
    $doc = $fields + [
        'filename' => 'cn ' . $docNo . '.pdf', 'doc_date' => '06.04.2026', 'customer' => '5002-CENTRAL PHUKET 10121', 'format_type' => 'Standard CN',
        'pages' => '1', 'store_no' => '10121', 'doc_date_iso' => '2026-04-06', 'shipped_units' => array_sum(array_column($items, 1)), 'promo_discount' => 0,
        'total_amount' => null, 'total_discount' => 0, 'after_discount' => 0, 'excl_vat' => null, 'vat' => null, 'incl_vat' => 0, 'gp_percent' => 28,
        'read_ok' => 1, 'read_note' => '',
    ];
    $pdo->prepare('INSERT OR IGNORE INTO job_cn_files (job_id, filename, file_path, page_count) VALUES (?, ?, ?, 1)')->execute([$jobId, $doc['filename'], $doc['filename']]);
    $fileId = (int)$pdo->query("SELECT id FROM job_cn_files WHERE job_id = {$jobId} AND filename = " . $pdo->quote($doc['filename']))->fetchColumn();
    $columns = array_keys($doc);
    $pdo->prepare('INSERT INTO job_cn_docs (job_id, file_id, doc_no, ' . implode(', ', $columns) . ') VALUES (?, ?, ?' . str_repeat(', ?', count($columns)) . ')')
        ->execute(array_merge([$jobId, $fileId, $docNo], array_values($doc)));
    $docId = (int)$pdo->lastInsertId();
    foreach ($items as $item) {
        $pdo->prepare('INSERT INTO job_cn_items (job_id, file_id, doc_id, filename, doc_no, barcode, units, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$jobId, $fileId, $docId, $doc['filename'], $docNo, $item[0], $item[1], $item[2], $item[3]]);
    }
}

/** An ASN record as the credit note page sends it */
function rec(string $no, string $ean, int $qty, string $kind, float $price, float $cost = 0, float $vat = 0, string $store = '10121'): array
{
    return [
        'invoice_no' => $no, 'invoice_date' => '2026-04-06', 'dest_store_no' => $store, 'ean_upc' => $ean, 'quantity' => $qty, 'selling_price' => $price,
        'total_cost_ex_vat' => $cost, 'total_vat' => $vat, 'delivery_no' => '', 'amount_kind' => $kind,
    ];
}

function import(int $jobId, string $source, array $records, array $extra = []): array
{
    return api('job_cn_import_asn', $extra + ['job_id' => $jobId, 'asn_filename' => $source, 'records' => json_encode($records)]);
}

function rowsOf(array $results, string $docNo): array
{
    return array_values(array_filter($results, function (array $row) use ($docNo): bool {
        return $row['asn']['doc_no'] === $docNo;
    }));
}

/** Status, reason and the document verdicts of each row: what the Remark and the colours of the grid come from */
function verdicts(array $rows): array
{
    return array_map(function (array $row): array {
        return [$row['status'], $row['reason'], $row['amount_ok'], $row['vat_ok']];
    }, $rows);
}

$isThai = function (string $text): bool {
    return preg_match('/[\x{0E00}-\x{0E7F}]/u', $text) === 1;
};

// What the reading stores, on real credit notes
$read = $jm->createJob('Reading');
$readId = (int)$read['id'];
foreach ([STD, TWO, ATTACHMENT] as $name) {
    copy(__DIR__ . '/fixtures/cn/' . $name, $jm->getJobCnUploadDir($read) . '/' . $name);
}
api('job_cn_extract_batch', ['job_id' => $readId, 'files' => json_encode([STD, TWO, ATTACHMENT])]);
$docs = api('job_cn_docs', [], ['job_id' => $readId])['data'] ?? [];
$byNo = array_column($docs, null, 'doc_no');
$std = $byNo['93251044-1'] ?? [];
$itemRows = function (int $jobId, string $docNo) use ($pdo): array {
    return $pdo->query("SELECT barcode, product_code, units, unit_price, amount FROM job_cn_items WHERE job_id = {$jobId} AND doc_no = " . $pdo->quote($docNo) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
};
check(
    'reading a Standard CN stores its right-hand block, store, ISO date and read_ok, and one item row per printed line',
    array_intersect_key($std, array_flip(['store_no', 'doc_date_iso', 'shipped_units', 'promo_discount', 'total_amount', 'total_discount', 'after_discount', 'excl_vat', 'vat', 'incl_vat', 'gp_percent', 'read_ok', 'read_note', 'has_items']))
        == ['store_no' => '20186', 'doc_date_iso' => '2026-04-09', 'shipped_units' => 1, 'promo_discount' => 438.0, 'total_amount' => 1752.0, 'total_discount' => 490.56,
            'after_discount' => 1261.44, 'excl_vat' => 1178.91, 'vat' => 82.53, 'incl_vat' => 1261.44, 'gp_percent' => 28.0, 'read_ok' => true, 'read_note' => '', 'has_items' => true]
        && $itemRows($readId, '93251044-1') == [['barcode' => '5401221448674', 'product_code' => '00505-31683332', 'units' => 1, 'unit_price' => 2190.0, 'amount' => 2190.0]],
    show([$std, $itemRows($readId, '93251044-1')])
);
check(
    'the two credit notes of one file each get their own item rows, and the Attachment Sheet is stored as read, without item rows and with read_ok false',
    count($itemRows($readId, '93247387-1')) > 0 && count($itemRows($readId, '93247387-2')) > 0
        && ($byNo['93260445']['has_items'] ?? null) === true && ($byNo['93260445']['read_ok'] ?? null) === false && $itemRows($readId, '93260445') === [],
    show([$byNo['93260445'] ?? null])
);
$itemsBefore = (int)$pdo->query("SELECT COUNT(*) FROM job_cn_items WHERE job_id = {$readId}")->fetchColumn();
$pdo->exec("UPDATE job_cn_docs SET " . implode(', ', array_map(function ($c) {
    return "{$c} = NULL";
}, ['store_no', 'doc_date_iso', 'shipped_units', 'promo_discount', 'total_amount', 'total_discount', 'after_discount', 'excl_vat', 'vat', 'incl_vat', 'gp_percent', 'read_ok', 'read_note'])) . " WHERE job_id = {$readId} AND filename = " . $pdo->quote(STD));
$pdo->exec("DELETE FROM job_cn_items WHERE job_id = {$readId} AND filename = " . $pdo->quote(STD));
$old = array_column(api('job_cn_docs', [], ['job_id' => $readId])['data'] ?? [], null, 'doc_no')['93251044-1'] ?? [];
$again = api('job_cn_extract_batch', ['job_id' => $readId, 'files' => json_encode([STD])]);
$renewed = array_column(api('job_cn_docs', [], ['job_id' => $readId])['data'] ?? [], null, 'doc_no')['93251044-1'] ?? [];
check(
    'a document stored before the line reader says has_items false; reading its file again by name from finished stores the reader fields and its lines once',
    ($old['has_items'] ?? null) === false && nullAt($old ?? [], 'read_ok') && ($again['processed_files'] ?? 0) === 1 && is_file($jm->getJobCnFinishedDir($read) . '/' . STD)
        && ($renewed['has_items'] ?? null) === true && ($renewed['excl_vat'] ?? null) == 1178.91
        && (int)$pdo->query("SELECT COUNT(*) FROM job_cn_items WHERE job_id = {$readId}")->fetchColumn() === $itemsBefore,
    show([$old, $again['processed_files'] ?? $again, $renewed])
);

// The compare, on made-up documents
$job = $jm->createJob('Compare');
$jobId = (int)$job['id'];
pdfDoc($jobId, '93000001-1', ['excl_vat' => 149.53, 'vat' => 10.47, 'total_amount' => 200], [['5400000000011', 1, 100, 100], ['5400000000012', 2, 50, 100]]);
pdfDoc($jobId, '93000002-1', ['excl_vat' => 149.53, 'vat' => 10.47, 'total_amount' => 200], [['5400000000021', 1, 200, 200]]);
pdfDoc($jobId, '93000003-1', ['excl_vat' => 149.53, 'vat' => 10.47, 'total_amount' => 200], [['5400000000031', 1, 200, 200]]);
pdfDoc($jobId, '93000004', ['format_type' => 'Variance / Refund', 'store_no' => '10158', 'pages' => '1-2', 'total_amount' => 3180, 'excl_vat' => 2139.81, 'vat' => 149.79], [['5400000000041', 1, 2390, 2390], ['5400000000042', 1, 790, 790]]);
pdfDoc($jobId, '93000005', ['format_type' => 'Variance / Refund', 'store_no' => '10158', 'total_amount' => 1580, 'excl_vat' => 1063.18, 'vat' => 74.42], [['5400000000051', 2, 790, 1580]]);
pdfDoc($jobId, '93000006', ['format_type' => 'Variance / Refund', 'store_no' => '10158', 'total_amount' => 500, 'excl_vat' => 336.45, 'vat' => 23.55], [
    ['5400000000060', 1, 100, 100], ['5400000000061', 1, 100, 100], ['5400000000062', 1, 100, 100], ['5400000000063', 1, 100, 100], ['5400000000064', 1, 100, 100],
]);
pdfDoc($jobId, '93000007-1', ['excl_vat' => 100, 'vat' => 7, 'total_amount' => 150], [['5400000000071', 1, 150, 150]]);
pdfDoc($jobId, '93000008-1', ['store_no' => null, 'customer' => '5152-CENTRAL NAKORN SAWAN', 'excl_vat' => 100, 'vat' => 7, 'total_amount' => 150], [['5400000000081', 1, 150, 150]]);
pdfDoc($jobId, '93000009-1', ['read_ok' => 0, 'read_note' => 'item 1 (5400000000091): units, unit price or amount not read', 'excl_vat' => 100, 'vat' => 7], [['5400000000091', null, null, null]]);
pdfDoc($jobId, '93000010', ['format_type' => 'Attachment Sheet', 'customer' => '-', 'store_no' => null, 'read_ok' => 0, 'read_note' => 'no item lines', 'shipped_units' => null], []);
pdfDoc($jobId, '93000011-1', ['store_no' => null, 'doc_date_iso' => null, 'shipped_units' => null, 'gp_percent' => null, 'read_ok' => null, 'read_note' => null, 'total_discount' => null, 'after_discount' => null, 'incl_vat' => null, 'promo_discount' => null], []);
pdfDoc($jobId, '93000012-1', ['excl_vat' => 100, 'vat' => 7, 'total_amount' => 150], [['5400000000121', 1, 150, 150]]);

$zfp3 = [
    rec('93000001-1', '5400000000011', 1, 'cost', 95, 149.53, 10.47),
    rec('93000001-1', '5400000000012', 1, 'cost', 45, 149.53, 10.47),
    rec('93000001-1', '5400000000012', 1, 'cost', 40, 149.53, 10.47),
    rec('93000001-2', '5400000000013', 1, 'cost', 40, 37.38, 2.62),
    rec('93000002-1', '5400000000021', 1, 'cost', 190, 149.54, 10.47, '010121'),
    rec('93000003-1', '5400000000031', 1, 'cost', 190, 149.53, 10.48),
    rec('93000007-1', '5400000000071', 1, 'cost', 140, 100, 7, '10116'),
    rec('93000008-1', '5400000000081', 1, 'cost', 140, 100, 7),
    rec('93000009-1', '5400000000091', 1, 'cost', 140, 100, 7),
    rec('93000011-1', '5400000000111', 1, 'cost', 140, 100, 7),
];
$zg2t = [
    rec('93000004', '5400000000041', 1, 'retail', 2390, 0, 0, '10158'),
    rec('93000004', '5400000000042', 1, 'retail', 790, 0, 0, '10158'),
    rec('93000005', '5400000000051', 1, 'retail', 790, 0, 0, '10158'),
    rec('93000006', '5400000000060', 1, 'retail', 100, 0, 0, '10158'),
    rec('93000006', '5400000000069', 1, 'retail', 400, 0, 0, '10158'),
    rec('93000010', '5400000000101', 1, 'retail', 2390, 0, 0, '10158'),
];
$ZFP3 = 'wb.xlsx / CDS ZFP3 (ZKR) done';
$ZG2T = 'wb.xlsx / CDS ZG2T (ZCR) done';

$noKind = $zfp3;
unset($noKind[3]['amount_kind']);
$refused = import($jobId, $ZFP3, $noKind);
$badKind = $zfp3;
$badKind[0]['amount_kind'] = 'Cost';
$refusedBad = import($jobId, $ZFP3, $badKind);
check(
    'an import with one record whose amount_kind is missing or not cost / retail is refused with a Thai message and stores no row',
    ($refused['status'] ?? '') === 'error' && $isThai($refused['message'] ?? '') && ($refusedBad['status'] ?? '') === 'error'
        && (int)$pdo->query("SELECT COUNT(*) FROM job_cn_asn_records WHERE job_id = {$jobId}")->fetchColumn() === 0,
    show([$refused, $refusedBad])
);

$first = import($jobId, $ZFP3, $zfp3);
$second = import($jobId, $ZG2T, $zg2t);
check(
    'each sheet imports as its own source: the answer keys of import_asn, the rows of the sheet, the label and counts by sheet, not compared yet',
    array_keys($second) === ['status', 'imported_records', 'skipped_invoices', 'replaced_invoices', 'skipped_same_file', 'replaced_same_file', 'asn_filename', 'asn_reconciled', 'asn_counts', 'reconcile', 'raw_records']
        && ($first['imported_records'] ?? 0) === 10 && ($first['asn_filename'] ?? '') === $ZFP3 && ($second['imported_records'] ?? 0) === 6
        && ($second['asn_filename'] ?? '') === '2 ชีต: ' . $ZFP3 . ', ' . $ZG2T && ($second['asn_counts'] ?? null) === ['files' => 2, 'rows' => 16]
        && ($second['asn_reconciled'] ?? null) === 0 && nullAt($second ?? [], 'reconcile') && count($second['raw_records'] ?? []) === 16,
    show(array_diff_key($second, ['raw_records' => 1]))
);
$records = api('job_cn_asn_records', [], ['job_id' => $jobId]);
check(
    'job_cn_asn_records lists every row with its source sheet, date and amount kind',
    ($records['count'] ?? 0) === 16 && array_keys($records['data'][0] ?? []) === ['id', 'source_file', 'invoice_no', 'invoice_date', 'dest_store_no', 'ean_upc', 'quantity', 'selling_price', 'total_cost_ex_vat', 'total_vat', 'delivery_no', 'amount_kind']
        && ($records['data'][15]['source_file'] ?? '') === $ZG2T && ($records['data'][15]['amount_kind'] ?? '') === 'retail' && ($records['data'][0]['invoice_date'] ?? '') === '2026-04-06',
    show($records['data'][0] ?? $records)
);

$dashBefore = api('job_cn_dashboard', [], ['job_id' => $jobId])['data'] ?? [];
check(
    'before the compare the dashboard lists every ASN credit note waiting, with no problem and the counts it cannot know yet empty',
    ($dashBefore['total'] ?? 0) === 12 && ($dashBefore['compared'] ?? null) === false && array_key_exists('complete', $dashBefore) && $dashBefore['complete'] === null
        && $dashBefore['error'] === null && $dashBefore['pending'] === 12 && array_unique(array_column($dashBefore['documents'], 'status')) === ['PENDING']
        && array_unique(array_column($dashBefore['documents'], 'problem')) === [''],
    show(array_diff_key($dashBefore, ['documents' => 1]))
);
$waiting = api('job_cn_reconcile', [], ['job_id' => $jobId]);
check(
    'job_cn_reconcile without force on a Job not compared yet answers the ASN rows and no compare',
    ($waiting['asn_reconciled'] ?? null) === 0 && nullAt($waiting ?? [], 'data') && count($waiting['raw_records'] ?? []) === 16,
    show(array_diff_key($waiting, ['raw_records' => 1]))
);

$compared = api('job_cn_reconcile', [], ['job_id' => $jobId, 'force' => 1]);
$results = $compared['data']['results'] ?? [];
$d1 = rowsOf($results, '93000001-1');
check(
    'a credit note whose pieces per barcode, Excl VAT and VAT equal the ASN is complete on every line, its two ASN rows of one barcode merged',
    verdicts($d1) === [['MATCH', '', true, true], ['MATCH', '', true, true]] && ($d1[1]['asn']['quantity'] ?? 0) === 2 && ($d1[1]['asn']['row_count'] ?? 0) === 2
        && ($d1[1]['asn']['selling_price'] ?? 0) == 40 && ($d1[1]['pdf']['units'] ?? 0) === 2 && ($d1[0]['asn']['doc_amount'] ?? 0) == 149.53 && ($d1[0]['asn']['total_vat'] ?? 0) == 10.47
        && ($d1[0]['source_files'] ?? null) === ['cn 93000001-1.pdf'] && ($d1[0]['amount_kind'] ?? '') === 'cost' && ($d1[0]['action'] ?? 'x') === '',
    show($d1)
);
$d1b = rowsOf($results, '93000001-2');
check(
    '-1 and -2 are two credit notes: the ASN number whose PDF is missing is wrong with ไม่พบใบลดหนี้นี้ใน PDF and no PDF side',
    verdicts($d1b) === [['MISMATCH', 'ไม่พบใบลดหนี้นี้ใน PDF', null, null]] && $d1b[0]['pdf'] === null && $d1b[0]['source_files'] === [],
    show($d1b)
);
check(
    'money is exact: Total Cost Ex VAT one satang above the PDF Excl VAT is wrong, with both figures (and an ASN store written with a leading zero is the same store)',
    verdicts(rowsOf($results, '93000002-1')) === [['MISMATCH', 'ยอดรวมไม่ตรง (PDF: 149.53 / ASN: 149.54)', false, true]],
    show(rowsOf($results, '93000002-1'))
);
check(
    'VAT is exact: Total VAT one satang above the PDF VAT is wrong, with both figures and the baht sign',
    verdicts(rowsOf($results, '93000003-1')) === [['MISMATCH', 'VAT ไม่ตรง (PDF: ฿10.47 / ASN: ฿10.48)', true, false]],
    show(rowsOf($results, '93000003-1'))
);
$d4 = rowsOf($results, '93000004');
check(
    'on the retail basis the ASN amount is quantity x Unit Ret summed over the document, compared with the PDF Total Amount, and VAT is not judged',
    verdicts($d4) === [['MATCH', '', true, null], ['MATCH', '', true, null]] && ($d4[0]['asn']['doc_amount'] ?? 0) == 3180 && nullAt($d4[0]['asn'] ?? [], 'total_vat')
        && ($d4[0]['amount_kind'] ?? '') === 'retail' && ($d4[0]['pdf']['doc_total_amount'] ?? 0) == 3180 && ($d4[0]['pdf']['doc_vat'] ?? 0) == 149.79,
    show($d4)
);
check(
    'a quantity difference names both counts on its line, before the amount it causes',
    verdicts(rowsOf($results, '93000005')) === [['MISMATCH', 'จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 1 ชิ้น), ยอดรวมไม่ตรง (PDF: 1,580.00 / ASN: 790.00)', false, null]],
    show(rowsOf($results, '93000005'))
);
$d6 = rowsOf($results, '93000006');
$oneSide = 'บาร์โค้ด 5400000000069 มีใน ASN แต่ไม่มีใน PDF (1 ชิ้น), บาร์โค้ด 5400000000061 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), บาร์โค้ด 5400000000062 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น), บาร์โค้ด 5400000000063 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น) และอีก 1 รายการ';
check(
    'barcodes on one side only make every line of the document wrong even when the amount agrees: ASN barcodes first, then the PDF ones, three shown and the rest counted, a row for each',
    verdicts($d6) === array_fill(0, 6, ['MISMATCH', $oneSide, true, null])
        && array_map(function ($r) {
            return [$r['asn']['ean_upc'], $r['pdf']['barcode'], $r['asn']['quantity'], $r['pdf']['units']];
        }, $d6) === [
            ['5400000000060', '5400000000060', 1, 1], ['5400000000069', null, 1, null], [null, '5400000000061', null, 1],
            [null, '5400000000062', null, 1], [null, '5400000000063', null, 1], [null, '5400000000064', null, 1],
        ]
        && ($d6[2]['asn']['doc_amount'] ?? 0) == 500 && ($d6[2]['asn']['dest_store_no'] ?? '') === '10158',
    show($d6)
);
check(
    'a store that differs is wrong, a store the PDF does not print is not judged',
    verdicts(rowsOf($results, '93000007-1')) === [['MISMATCH', 'รหัสสาขาไม่ตรง (PDF: 10121 / ASN: 10116)', true, true]]
        && verdicts(rowsOf($results, '93000008-1')) === [['MATCH', '', true, true]],
    show([rowsOf($results, '93000007-1'), rowsOf($results, '93000008-1')])
);
$d9 = rowsOf($results, '93000009-1');
check(
    'a credit note whose reading does not add up is not judged: one reason with the reader\'s note, no verdict on money, no line on the PDF side',
    verdicts($d9) === [['MISMATCH', 'อ่านใบลดหนี้ไม่ครบ: item 1 (5400000000091): units, unit price or amount not read', null, null]]
        && nullAt($d9[0]['pdf'] ?? [], 'units') && nullAt($d9[0]['pdf'] ?? [], 'barcode') && ($d9[0]['pdf']['doc_excl_vat'] ?? 0) == 100,
    show($d9)
);
check(
    'an Attachment Sheet has nothing to compare and is wrong on every line, asking for a check by eye',
    verdicts(rowsOf($results, '93000010')) === [['MISMATCH', 'ใบแนบ (Attachment Sheet) ไม่มีบาร์โค้ดและยอดท้ายใบให้เทียบ ตรวจด้วยตา', null, null]],
    show(rowsOf($results, '93000010'))
);
check(
    'a credit note stored before the line reader is not judged and says why',
    verdicts(rowsOf($results, '93000011-1')) === [['MISMATCH', 'ใบลดหนี้นี้ดึงข้อมูลไว้ก่อนรุ่นที่อ่านรายการสินค้าและยอดท้ายใบ จึงยังเทียบไม่ได้', null, null]],
    show(rowsOf($results, '93000011-1'))
);
check(
    'a credit note PDF without ASN rows is not in the compare, and the summary counts ASN rows, merged lines and rows by status',
    rowsOf($results, '93000012-1') === [] && ($compared['data']['summary'] ?? null) === ['total_asn_records' => 16, 'total_asn_groups' => 15, 'matched' => 5, 'mismatch' => 14, 'total_compared' => 19]
        && ($compared['asn_reconciled'] ?? null) === 1 && ($compared['asn_counts'] ?? null) === ['files' => 2, 'rows' => 16],
    show($compared['data']['summary'] ?? $compared)
);

$dash = api('job_cn_dashboard', [], ['job_id' => $jobId])['data'] ?? [];
$dashRows = array_column($dash['documents'] ?? [], null, 'doc_no');
check(
    'after the compare the dashboard counts credit notes by their worst line and orders them by store, then number',
    ($dash['compared'] ?? null) === true && ($dash['total'] ?? 0) === 12 && ($dash['complete'] ?? 0) === 3 && ($dash['error'] ?? 0) === 9 && ($dash['pending'] ?? -1) === 0
        && array_column($dash['documents'], 'doc_no') === ['93000007-1', '93000001-1', '93000001-2', '93000002-1', '93000003-1', '93000008-1', '93000009-1', '93000011-1', '93000004', '93000005', '93000006', '93000010'],
    show(array_column($dash['documents'] ?? [], 'doc_no'))
);
check(
    'a dashboard row shows the PDF pieces and pages, the ASN amount on its basis, the source file, and the problems as topics with the barcode of a line problem, three then the count of the rest',
    ($dashRows['93000004'] ?? null) == ['doc_no' => '93000004', 'store_no' => '10158', 'source_files' => ['cn 93000004.pdf'], 'units' => 2, 'amount' => 3180, 'amount_kind' => 'retail', 'pages' => 2, 'status' => 'MATCH', 'problem' => '']
        && ($dashRows['93000005']['problem'] ?? '') === 'บาร์โค้ด 5400000000051: จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 1 ชิ้น), ยอดรวมไม่ตรง (PDF: 1,580.00 / ASN: 790.00)'
        && ($dashRows['93000006']['problem'] ?? '') === $oneSide
        && ($dashRows['93000001-2'] ?? null) == ['doc_no' => '93000001-2', 'store_no' => '10121', 'source_files' => [], 'units' => null, 'amount' => 37.38, 'amount_kind' => 'cost', 'pages' => null, 'status' => 'MISMATCH', 'problem' => 'ไม่พบใบลดหนี้นี้ใน PDF']
        && nullAt($dashRows['93000010'] ?? [], 'units') && ($dashRows['93000008-1']['store_no'] ?? '') === '10121',
    show([$dashRows['93000004'] ?? null, $dashRows['93000005'] ?? null, $dashRows['93000006'] ?? null, $dashRows['93000001-2'] ?? null])
);

$same = api('job_cn_asn_overlap', ['job_id' => $jobId, 'asn_filename' => $ZG2T, 'records' => json_encode($zg2t)]);
$skip = import($jobId, $ZG2T, $zg2t, ['same_file_mode' => 'skip']);
check(
    'the same sheet again is told as stored, identical and compared; importing it with skip stores nothing and keeps the compare',
    ($same['same_file'] ?? null) === ['stored' => 6, 'incoming' => 6, 'identical' => true, 'reconciled' => true] && ($same['overlap_count'] ?? -1) === 0
        && ($skip['skipped_same_file'] ?? null) === true && ($skip['imported_records'] ?? -1) === 0 && ($skip['asn_reconciled'] ?? null) === 1 && ($skip['asn_counts']['rows'] ?? 0) === 16,
    show([$same, array_diff_key($skip, ['raw_records' => 1, 'reconcile' => 1])])
);
$changed = $zg2t;
$changed[2]['quantity'] = 2;
$replace = import($jobId, $ZG2T, $changed, ['reconcile' => 1]);
check(
    'replacing a sheet swaps its rows without doubling, and with reconcile the answer carries the compare on the new rows',
    ($replace['replaced_same_file'] ?? null) === true && ($replace['asn_counts']['rows'] ?? 0) === 16 && ($replace['asn_reconciled'] ?? null) === 1 && nullAt($replace ?? [], 'raw_records')
        && verdicts(rowsOf($replace['reconcile']['results'] ?? [], '93000005')) === [['MATCH', '', true, null]],
    show(rowsOf($replace['reconcile']['results'] ?? [], '93000005'))
);
$plain = import($jobId, $ZG2T, $zg2t);
check(
    'replacing a sheet without reconcile sends the Job back to waiting and answers the rows',
    ($plain['asn_reconciled'] ?? null) === 0 && nullAt($plain ?? [], 'reconcile') && count($plain['raw_records'] ?? []) === 16
        && (api('job_cn_dashboard', [], ['job_id' => $jobId])['data']['pending'] ?? 0) === 12,
    show(array_diff_key($plain, ['raw_records' => 1]))
);

$other = 'other.xlsx / RBS ZG2T (ZCR) done';
$overlapping = [rec('93000004', '5400000000041', 1, 'retail', 2390, 0, 0, '10158'), rec('93000099', '5400000000991', 1, 'retail', 100, 0, 0, '10158')];
$overlap = api('job_cn_asn_overlap', ['job_id' => $jobId, 'asn_filename' => $other, 'records' => json_encode($overlapping)]);
$skipDup = import($jobId, $other, $overlapping);
$sources = function () use ($pdo, $jobId): array {
    return $pdo->query("SELECT invoice_no, source_file, COUNT(*) AS n FROM job_cn_asn_records WHERE job_id = {$jobId} AND invoice_no IN ('93000004', '93000099') GROUP BY invoice_no, source_file ORDER BY invoice_no")->fetchAll(PDO::FETCH_ASSOC);
};
$afterSkip = $sources();
$replaceDup = import($jobId, $other, $overlapping, ['duplicate_mode' => 'replace']);
check(
    'a credit note number another sheet holds is named by the overlap question, left out with skip and taken over with replace',
    ($overlap['overlap_count'] ?? 0) === 1 && ($overlap['files'] ?? null) === [$ZG2T] && ($overlap['sample'] ?? null) === ['93000004']
        && ($skipDup['skipped_invoices'] ?? 0) === 1 && ($skipDup['imported_records'] ?? 0) === 1
        && $afterSkip == [['invoice_no' => '93000004', 'source_file' => $ZG2T, 'n' => 2], ['invoice_no' => '93000099', 'source_file' => $other, 'n' => 1]]
        && ($replaceDup['replaced_invoices'] ?? 0) === 1 && $sources() == [['invoice_no' => '93000004', 'source_file' => $other, 'n' => 1], ['invoice_no' => '93000099', 'source_file' => $other, 'n' => 1]],
    show([$overlap, $afterSkip, $sources()])
);

api('job_cn_reconcile', [], ['job_id' => $jobId, 'force' => 1]);
$otherIds = array_column(array_filter(api('job_cn_asn_records', [], ['job_id' => $jobId])['data'] ?? [], function ($r) use ($other) {
    return $r['source_file'] === $other;
}), 'id');
$deleted = api('job_cn_delete_asn', ['job_id' => $jobId, 'ids' => json_encode($otherIds)]);
check(
    'deleting the rows of one sheet of a compared Job keeps it compared and answers the compare on the rows left',
    ($deleted['deleted_count'] ?? 0) === 2 && ($deleted['asn_reconciled'] ?? null) === 1 && nullAt($deleted ?? [], 'raw_records')
        && rowsOf($deleted['reconcile']['results'] ?? [], '93000004') === [] && ($deleted['asn_counts'] ?? null) === ['files' => 2, 'rows' => 14],
    show(array_diff_key($deleted, ['reconcile' => 1]))
);

$tiEntries = [['filename' => 'ti.pdf', 'storedPath' => '', 'items' => [['page_no' => 1, 'tax_invoice' => '93000001-1', 'barcode' => '5400000000011', 'units' => 1, 'unit_price' => '100.00', 'amount' => '100.00', 'amt_excl_vat' => '149.53', 'vat_amount' => '10.47']]]];
$jm->recordExtractedBatch($jobId, $tiEntries, true);
$jm->saveAsnRecords($jobId, 'ti.xlsx [s]', [['invoice_no' => '93000001-1', 'ean_upc' => '5400000000011', 'quantity' => 1, 'dest_store_no' => '10121', 'selling_price' => 1, 'total_cost_ex_vat' => 149.53, 'total_vat' => 10.47]]);
api('get_job_reconcile', [], ['job_id' => $jobId, 'force' => 1]);
$tiAnswers = function () use ($jobId): array {
    $summary = api('job_delete_summary', [], ['job_id' => $jobId])['data'] ?? [];
    unset($summary['cn_files'], $summary['cn_documents'], $summary['cn_asn_rows']);
    return [api('get_asn_records', [], ['job_id' => $jobId]), api('get_job_reconcile', [], ['job_id' => $jobId]), api('get_job_dashboard', [], ['job_id' => $jobId]), $summary];
};
$tiBefore = $tiAnswers();
import($jobId, $other, $overlapping, ['reconcile' => 1]);
api('job_cn_delete_asn', ['job_id' => $jobId, 'ids' => json_encode(array_column(api('job_cn_asn_records', [], ['job_id' => $jobId])['data'] ?? [], 'id'))]);
import($jobId, $ZFP3, $zfp3);
api('job_cn_reconcile', [], ['job_id' => $jobId, 'force' => 1]);
$tiAfter = $tiAnswers();
check(
    'the Tax Invoice side answers exactly as before while credit note ASN sheets are imported, compared and deleted, and its ASN rows never reach the credit note compare',
    $tiBefore === $tiAfter && ($tiBefore[1]['asn_reconciled'] ?? 0) === 1 && count($tiBefore[0]['data'] ?? []) === 1
        && (api('job_cn_asn_records', [], ['job_id' => $jobId])['count'] ?? 0) === 10,
    show([$tiBefore[1]['data']['summary'] ?? null, $tiAfter[1]['data']['summary'] ?? null])
);
$deletedAll = api('job_cn_delete_asn', ['job_id' => $jobId, 'ids' => json_encode(array_column(api('job_cn_asn_records', [], ['job_id' => $jobId])['data'] ?? [], 'id'))]);
$emptyCompare = api('job_cn_reconcile', [], ['job_id' => $jobId, 'force' => 1]);
check(
    'deleting every ASN row ends the compare; comparing without ASN rows answers no summary and no row and stays not compared',
    ($deletedAll['asn_reconciled'] ?? null) === 0 && ($deletedAll['raw_records'] ?? null) === [] && nullAt($deletedAll ?? [], 'asn_filename')
        && ($emptyCompare['data'] ?? null) === ['summary' => null, 'results' => []] && ($emptyCompare['asn_reconciled'] ?? null) === 0
        && (api('job_cn_dashboard', [], ['job_id' => $jobId])['data']['total'] ?? -1) === 0,
    show([array_diff_key($deletedAll, ['raw_records' => 1]), $emptyCompare])
);

import($jobId, $ZFP3, $zfp3, ['reconcile' => 1]);
$cleared = api('job_cn_clear', ['job_id' => $jobId]);
$afterClear = api('job_cn_reconcile', [], ['job_id' => $jobId]);
$counts = api('job_cn_docs', [], ['job_id' => $jobId])['counts'] ?? null;
check(
    'job_cn_clear forgets the credit note documents and their lines, keeps the ASN rows and sends the compare back to waiting',
    ($cleared['status'] ?? '') === 'success' && ($afterClear['asn_reconciled'] ?? null) === 0 && nullAt($afterClear ?? [], 'data')
        && $counts === ['files' => 0, 'documents' => 0, 'asn' => ['files' => 1, 'rows' => 10]]
        && (int)$pdo->query("SELECT COUNT(*) FROM job_cn_items WHERE job_id = {$jobId}")->fetchColumn() === 0,
    show([$cleared, $counts])
);

$errors = [];
foreach ([
    ['job_cn_import_asn', ['job_id' => 999999, 'asn_filename' => $ZFP3, 'records' => json_encode($zfp3)], []],
    ['job_cn_import_asn', ['job_id' => $jobId, 'asn_filename' => $ZFP3, 'records' => '[]'], []],
    ['job_cn_import_asn', ['job_id' => $jobId, 'asn_filename' => '', 'records' => json_encode($zfp3)], []],
    ['job_cn_asn_overlap', ['job_id' => 999999, 'asn_filename' => $ZFP3, 'records' => '[]'], []],
    ['job_cn_asn_records', [], ['job_id' => 999999]],
    ['job_cn_delete_asn', ['job_id' => 999999, 'ids' => '[]'], []],
    ['job_cn_reconcile', [], ['job_id' => 999999]],
    ['job_cn_dashboard', [], ['job_id' => 999999]],
] as $i => [$action, $post, $get]) {
    $res = api($action, $post, $get);
    if (($res['status'] ?? '') !== 'error' || !$isThai($res['message'] ?? '')) {
        $errors[$i . ' ' . $action] = $res;
    }
}
check('each compare action answers a Thai error for a Job that does not exist, an import without rows and one without a sheet name', $errors === [], show($errors));

$summary = api('job_delete_summary', [], ['job_id' => $jobId]);
$portal = $jm->creditNoteCounts($jobId);
$summaryFile = $root . '/delete_summary.json';
file_put_contents($summaryFile, json_encode($summary['data'] ?? []));
$dialog = json_decode((string)shell_exec('node ' . escapeshellarg(__DIR__ . '/index_navigation.js') . ' ' . escapeshellarg($summaryFile) . ' 2>&1'), true);
if (is_array($dialog)) {
    check(
        'the delete dialog of the Job list names the credit note ASN rows the Job holds, from the real job_delete_summary answer',
        in_array('ASN ของใบลดหนี้ 10 รายการ', $dialog['serverLines'] ?? [], true) && !in_array(true, array_map(function ($line) {
            return strpos($line, 'ASN ของใบลดหนี้') !== false;
        }, $dialog['withoutCreditNotes'] ?? ['ASN ของใบลดหนี้']), true),
        show($dialog['serverLines'] ?? $dialog)
    );
} else {
    echo "SKIP  node is not on PATH: the delete dialog line of the credit note ASN rows is not checked\n";
}
api('delete_job', ['job_id' => $jobId, 'confirm_name' => 'Compare']);
$left = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM job_cn_asn_records WHERE job_id = {$jobId}) + (SELECT COUNT(*) FROM job_cn_state WHERE job_id = {$jobId}) + (SELECT COUNT(*) FROM job_cn_items WHERE job_id = {$jobId})")->fetchColumn();
check(
    'the delete summary and the portal count the credit note ASN rows, and deleting the Job removes them with its compare state',
    ($summary['data']['cn_asn_rows'] ?? null) === 10 && $portal['asn'] === ['files' => 1, 'rows' => 10] && $left === 0 && $jm->getJob($jobId) === null,
    show([$summary['data'] ?? $summary, $portal, $left])
);

// A version 8 database: the credit note documents it holds keep their values and get the reader's columns empty
$v8Path = $root . '/v8.sqlite';
$v8 = new PDO('sqlite:' . $v8Path);
$v8->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$v8->exec('PRAGMA journal_mode = WAL');
Database::initSchema($v8);
foreach (['job_cn_items', 'job_cn_asn_records', 'job_cn_state', 'job_cn_docs'] as $table) {
    $v8->exec("DROP TABLE {$table}");
}
$v8->exec("
    CREATE TABLE job_cn_docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, file_id INTEGER NOT NULL, filename TEXT NOT NULL, doc_no TEXT NOT NULL,
        doc_date TEXT, customer TEXT, format_type TEXT NOT NULL, pages TEXT, orig_amount REAL DEFAULT NULL, corr_amount REAL DEFAULT NULL,
        diff_amount REAL DEFAULT NULL, vat_amount REAL DEFAULT NULL, amount REAL DEFAULT NULL,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE, FOREIGN KEY (file_id) REFERENCES job_cn_files(id) ON DELETE CASCADE
    );
    INSERT INTO jobs (name) VALUES ('Old Job');
    INSERT INTO job_cn_files (job_id, filename, file_path) VALUES (1, 'a.pdf', 'a.pdf');
    INSERT INTO job_cn_docs (job_id, file_id, filename, doc_no, doc_date, customer, format_type, pages, vat_amount, amount)
        VALUES (1, 1, 'a.pdf', '93251044-1', '09.04.2026', '5048-ROBINSON BOWIN 20186', 'Standard CN', '1', 82.53, 1261.44);
    PRAGMA user_version = 8;
");
Database::migrateSchema($v8);
$tablesV9 = $v8->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'job_cn_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$oldDoc = $v8->query('SELECT doc_no, amount, vat_amount, store_no, excl_vat, read_ok, read_note FROM job_cn_docs')->fetchAll(PDO::FETCH_ASSOC);
check(
    'a version 8 database moves to version 10: its Jobs and credit note documents stay, the reader\'s columns are added empty and the item, ASN, state and fill tables exist',
    (int)$v8->query('PRAGMA user_version')->fetchColumn() === 10 && $tablesV9 === ['job_cn_asn_records', 'job_cn_docs', 'job_cn_files', 'job_cn_fill_files', 'job_cn_fill_rows', 'job_cn_fill_sheets', 'job_cn_items', 'job_cn_state']
        && $oldDoc == [['doc_no' => '93251044-1', 'amount' => 1261.44, 'vat_amount' => 82.53, 'store_no' => null, 'excl_vat' => null, 'read_ok' => null, 'read_note' => null]]
        && (int)$v8->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 1,
    show([$tablesV9, $oldDoc])
);
$v8 = null;

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
