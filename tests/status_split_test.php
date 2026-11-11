<?php
// Run: php tests/status_split_test.php
// The two waiting statuses of the Tax Invoice compare (รอแก้ไข PDF and รอแก้ไข ASN, the user's decision of 2026-10-28)
// and the history of every bill: BillStatus, the engine on small Jobs with VBRP rows, the fix button, the print plan and
// print_start, the dashboard, and BillHistory with the api.php actions that record it. Runs against a throwaway root
// (PDF_READER_ROOT), never touches data/ or myfile/. The workbooks are written here, so no sample file is needed.

$mode = $argv[1] ?? '';

if ($mode === '--api') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = ['action' => $request['action']] + $request['get'];
    $_POST = $request['post'];
    require __DIR__ . '/../api.php';
    exit;
}

if ($mode !== '--run') {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_split_' . bin2hex(random_bytes(4));
    mkdir($root . '/data', 0777, true);
    mkdir($root . '/myfile', 0777, true);
    putenv('PDF_READER_ROOT=' . $root);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run', $code);
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

require __DIR__ . '/../vendor/autoload.php';

use App\AsnReconciler;
use App\BillHistory;
use App\BillStatus;
use App\Database;
use App\JobManager;
use App\PdfFixer;
use App\PrintManager;
use App\VbrpStore;

$jm = new JobManager();
$pdo = Database::getConnection();
$failures = 0;
$work = getenv('PDF_READER_ROOT') . DIRECTORY_SEPARATOR . 'books';
mkdir($work);

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
    $requestFile = tempnam(sys_get_temp_dir(), 'split_req_');
    file_put_contents($requestFile, json_encode(['action' => $action, 'post' => $post, 'get' => $get]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($requestFile));
    unlink($requestFile);
    return json_decode($out, true) ?: [];
}

function j($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

/** A workbook of one sheet given as [row number => [column letter => value]]: a string as a shared string, a number as is */
function writeWorkbook(string $path, array $rows): void
{
    $strings = [];
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowNo => $cells) {
        $xml .= '<row r="' . $rowNo . '">';
        foreach ($cells as $column => $value) {
            if (is_string($value)) {
                $strings[] = $value;
                $xml .= '<c r="' . $column . $rowNo . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
            } elseif ($value !== null) {
                $xml .= '<c r="' . $column . $rowNo . '"><v>' . $value . '</v></c>';
            }
        }
        $xml .= '</row>';
    }
    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach ($strings as $string) {
        $shared .= '<si><t>' . htmlspecialchars($string) . '</t></si>';
    }
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="ZFP" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', $shared . '</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml . '</sheetData></worksheet>');
    $zip->close();
}

/** A VBRP workbook of rows [invoice, barcode, pieces, Subtotal 4, Tax Amount], every one counted (Subtotal 3 of -10) */
function vbrpBook(string $name, array $rows): string
{
    global $work;
    $sheet = [1 => ['A' => 'VBRP'], 2 => ['C' => 'Invoice'], 3 => VbrpStore::LABELS];
    foreach (array_values($rows) as $i => [$invoice, $barcode, $pieces, $cost, $vat]) {
        $sheet[4 + $i] = ['A' => 'C' . $i, 'C' => $invoice, 'E' => '51000000' . $i, 'H' => $pieces, 'CI' => 10, 'CJ' => -10, 'CK' => $cost, 'CQ' => $barcode, 'EM' => $vat];
    }
    $path = $work . DIRECTORY_SEPARATOR . $name;
    writeWorkbook($path, $sheet);
    return $path;
}

function asnRecords(array $asn): array
{
    return array_map(function ($a) {
        return ['invoice_no' => $a[0], 'ean_upc' => $a[1], 'quantity' => $a[2], 'selling_price' => 10, 'total_cost_ex_vat' => $a[3], 'total_vat' => $a[4], 'dest_store_no' => '10166', 'delivery_no' => ''];
    }, $asn);
}

/**
 * A Job: ASN rows [invoice, barcode, pieces, Total Cost Ex VAT, Total VAT]; PDF files as filename => rows [page, the
 * invoice the page prints, barcode, pieces, Amt Excl VAT, VAT]; VBRP rows as vbrpBook takes them (none: no VBRP)
 */
function makeJob(JobManager $jm, string $label, array $asn, array $files, array $vbrp): int
{
    $jobId = (int)$jm->createJob($label)['id'];
    $jm->saveAsnRecords($jobId, 'asn.xlsx [s]', asnRecords($asn));
    $entries = [];
    foreach ($files as $filename => $rows) {
        $items = [];
        foreach ($rows as [$page, $invoice, $barcode, $pieces, $excl, $vat]) {
            $items[] = [
                'tax_invoice' => $invoice, 'page_no' => $page, 'barcode' => $barcode, 'units' => $pieces, 'amount' => 100, 'total_amount' => 'Null',
                'amt_excl_vat' => $excl, 'vat_amount' => $vat, 'ship_to_party' => '', 'address' => '',
            ];
        }
        $entries[] = ['filename' => $filename, 'storedPath' => '', 'items' => $items];
    }
    $jm->recordExtractedBatch($jobId, $entries, true);
    if ($vbrp) {
        $jm->importVbrp($jobId, vbrpBook("job{$jobId}.xlsx", $vbrp), "job{$jobId}.xlsx");
    }
    return $jobId;
}

/** The compare lines of a Job as the grid gets them (a page load): "invoice barcode" => row */
function gridLines(int $jobId): array
{
    $lines = [];
    foreach (api('get_job_reconcile', [], ['job_id' => $jobId, 'force' => 1])['data']['results'] ?? [] as $row) {
        $lines[$row['asn']['invoice_no'] . ' ' . $row['asn']['ean_upc']] = $row;
    }
    return $lines;
}

function brief(array $row): array
{
    return [$row['status'] ?? null, $row['reason'] ?? null, $row['action'] ?? null, $row['sources']['notes'] ?? 'no sources'];
}

function historyRows(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT invoice_no, event, from_status, to_status FROM job_bill_history WHERE job_id = :job_id ORDER BY id');
    $stmt->execute([':job_id' => $jobId]);
    return array_map(function ($row) {
        return implode(' ', [$row['invoice_no'], $row['event'], $row['from_status'] ?? '-', $row['to_status']]);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function itemInvoices(JobManager $jm, int $jobId): array
{
    $invoices = [];
    foreach ($jm->getJobItems($jobId) as $item) {
        $invoices[$item['filename'] . ' p' . $item['page_no'] . ' ' . $item['barcode']] = $item['tax_invoice'];
    }
    ksort($invoices);
    return $invoices;
}

// --- BillStatus
check(
    'the registry lists the four statuses best to worst with the user\'s labels, only a waiting PDF can be fixed and only a match prints',
    BillStatus::codes() === ['MATCH', 'PENDING_FIX', 'PENDING_ASN', 'MISMATCH']
        && array_map([BillStatus::class, 'label'], BillStatus::codes()) === ['สมบูรณ์', 'รอแก้ไข PDF', 'รอแก้ไข ASN', 'ผิดพลาด']
        && array_map([BillStatus::class, 'rank'], BillStatus::codes()) === [0, 1, 2, 3]
        && array_map([BillStatus::class, 'canFix'], BillStatus::codes()) === [false, true, false, false]
        && array_map([BillStatus::class, 'canPrint'], BillStatus::codes()) === [true, false, false, false],
    j(array_map([BillStatus::class, 'label'], BillStatus::codes()))
);
check(
    'a bill has the worst status of its lines: waiting for the ASN is worse than waiting for the fix and better than wrong',
    BillStatus::worst(['MATCH', 'PENDING_ASN', 'PENDING_FIX']) === 'PENDING_ASN' && BillStatus::worst(['PENDING_ASN', 'MISMATCH', 'MATCH']) === 'MISMATCH'
        && BillStatus::worst(['PENDING_FIX', 'MATCH']) === 'PENDING_FIX' && BillStatus::worst(['MATCH']) === 'MATCH'
        && AsnReconciler::invoiceStatuses([
            ['status' => 'PENDING_ASN', 'asn' => ['invoice_no' => '9-1']], ['status' => 'PENDING_FIX', 'asn' => ['invoice_no' => '9-1']],
            ['status' => 'MISMATCH', 'asn' => ['invoice_no' => '9-2']], ['status' => 'PENDING_ASN', 'asn' => ['invoice_no' => '9-2']],
        ]) === ['9-1' => 'PENDING_ASN', '9-2' => 'MISMATCH']
);

// --- the engine
$page = 'หน้า 1 คาดว่าเลขบิลผิด เนื่องจากรายการถูกต้องตาม ASN';
$engine = makeJob($jm, 'Engine',
    [
        ['93280001-1', '8850000000011', 1, 500.00, 21.00],
        ['93280003-1', '8850000000031', 1, 300.00, 21.00],
        ['93280005-1', '8850000000051', 1, 900.00, 21.00],
        ['93280006-1', '8850000000061', 3, 300.00, 21.00], ['93280006-1', '8850000000062', 1, 300.00, 21.00],
        ['93280007-1', '8850000000071', 1, 300.00, 21.00],
        ['93280008-1', '8850000000081', 1, 500.00, 21.00],
        ['93280009-1', '8850000000091', 1, 300.00, 21.00],
        ['93280010-1', '8850000000101', 1, 500.00, 21.00],
    ],
    [
        'a.pdf' => [[1, '93280001-2', '8850000000011', 1, '300.00', '21.00']],
        'c.pdf' => [[1, '93280003-1', '8850000000031', 1, '300.00', '21.00']],
        'd.pdf' => [[1, '93280005-1', '8850000000051', 1, '700.00', '21.00']],
        'e.pdf' => [[1, '93280006-1', '8850000000061', 2, '300.00', '21.00'], [1, '93280006-1', '8850000000062', 1, '300.00', '21.00']],
        'f.pdf' => [[1, '93280007-1', '8850000000071', 1, '300.00', '21.00'], [1, '93280007-1', '8850000000079', 2, '300.00', '21.00']],
        'g.pdf' => [[1, '93280008-1', '8850000000081', 1, '300.00', '21.00']],
        'h.pdf' => [[1, '93280009-1', '8850000000091', 1, '300.00', '21.00']],
        'k.pdf' => [[1, '93280010-1', '8850000000101', 1, '300.00', '21.00']],
    ],
    [
        ['93280001-1', '8850000000011', 1, 300.00, 21.00],
        ['93280003-1', '8850000000031', 1, 300.00, 21.00],
        ['93280005-1', '8850000000051', 1, 500.00, 21.00],
        ['93280006-1', '8850000000061', 2, 150.00, 10.50], ['93280006-1', '8850000000062', 1, 150.00, 10.50],
        ['93280007-1', '8850000000071', 1, 100.00, 7.00], ['93280007-1', '8850000000079', 2, 200.00, 14.00],
        ['93280009-1', '8850000000091', 2, 300.00, 21.00],
        ['93280010-1', '8850000000101', 1, 300.00, 21.00],
    ]
);
$lines = gridLines($engine);
check(
    'ASN wrong while the PDF equals VBRP: the bill waits for the ASN, the problem names the ASN figure and the two that agree, the action is the user\'s, nothing to fix or print',
    brief($lines['93280010-1 8850000000101'] ?? []) === ['PENDING_ASN', 'ASN ยอดก่อน VAT ไม่ตรง (ASN 500.00 / PDF 300.00 = VBRP 300.00)', 'แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่', []]
        && [$lines['93280010-1 8850000000101']['status_label'], $lines['93280010-1 8850000000101']['can_fix'], $lines['93280010-1 8850000000101']['culprit'], $lines['93280010-1 8850000000101']['amount_ok']] === ['รอแก้ไข ASN', false, '', false]
        && ($lines['93280010-1 8850000000101']['sources']['overall'] ?? null) === ['pdf' => 'ok', 'asn' => 'wrong', 'vbrp' => 'ok'],
    j($lines['93280010-1 8850000000101'] ?? null)
);
check(
    'a bill that waits for the fix button and whose ASN is wrong waits for the ASN, and its note says what the PDF will still need after the ASN is right; its PDF is not wrong',
    brief($lines['93280001-1 8850000000011'] ?? []) === ['PENDING_ASN', 'ASN ยอดก่อน VAT ไม่ตรง (ASN 500.00 / PDF 300.00 = VBRP 300.00)', AsnReconciler::FIX_ASN,
        ['หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: ' . $page]]
        && ($lines['93280001-1 8850000000011']['sources']['overall']['pdf'] ?? null) === 'ok',
    j(brief($lines['93280001-1 8850000000011'] ?? []))
);
check(
    'a PDF wrong while the ASN is wrong too stays wrong: all three amounts differ, the problem is the PDF\'s with the three figures',
    brief($lines['93280005-1 8850000000051'] ?? []) === ['MISMATCH', 'ยอดรวมไม่ตรง (PDF: 700.00 / ASN: 900.00 / VBRP: 500.00)', '', []]
        && ($lines['93280005-1 8850000000051']['sources']['overall'] ?? null) === ['pdf' => 'wrong', 'asn' => 'wrong', 'vbrp' => 'wrong'],
    j(brief($lines['93280005-1 8850000000051'] ?? []))
);
check(
    'a quantity the ASN alone gets wrong makes every line of the bill wait for the ASN, the line named by its barcode; the line\'s own note of that check goes, the other line keeps its notes',
    brief($lines['93280006-1 8850000000061'] ?? []) === ['PENDING_ASN', 'บาร์โค้ด 8850000000061: ASN จำนวน ไม่ตรง (ASN 3 ชิ้น / PDF 2 ชิ้น = VBRP 2 ชิ้น)', AsnReconciler::FIX_ASN, []]
        && brief($lines['93280006-1 8850000000062'] ?? []) === ['PENDING_ASN', 'บาร์โค้ด 8850000000061: ASN จำนวน ไม่ตรง (ASN 3 ชิ้น / PDF 2 ชิ้น = VBRP 2 ชิ้น)', AsnReconciler::FIX_ASN, []],
    j([brief($lines['93280006-1 8850000000061'] ?? []), brief($lines['93280006-1 8850000000062'] ?? [])])
);
check(
    'a barcode the PDF and VBRP have and the ASN lacks makes the bill wait for the ASN with that sentence as its problem, not repeated as a note',
    brief($lines['93280007-1 8850000000071'] ?? []) === ['PENDING_ASN', 'ASN ไม่มีบาร์โค้ด 8850000000079 (2 ชิ้น) ที่ PDF และ VBRP มี', AsnReconciler::FIX_ASN, []],
    j(brief($lines['93280007-1 8850000000071'] ?? []))
);
check(
    'a bill whose ASN is right is untouched: complete, and a VBRP fault stays a note',
    brief($lines['93280009-1 8850000000091'] ?? []) === ['MATCH', AsnReconciler::COMPLETE, AsnReconciler::COMPLETE, ['VBRP ไม่ตรง: จำนวน 2 ชิ้น (PDF: 1 ชิ้น / ASN: 1 ชิ้น)']]
        && brief($lines['93280003-1 8850000000031'] ?? []) === ['MATCH', AsnReconciler::COMPLETE, AsnReconciler::COMPLETE, []],
    j([brief($lines['93280009-1 8850000000091'] ?? []), brief($lines['93280003-1 8850000000031'] ?? [])])
);
check(
    'a bill VBRP does not cover is wrong (VBRP is the reference): ไม่พบใน VBRP first, then its PDF/ASN problems, with 2-way verdicts and vbrp na',
    [$lines['93280008-1 8850000000081']['status'] ?? '', $lines['93280008-1 8850000000081']['reason'] ?? '', $lines['93280008-1 8850000000081']['sources']['overall'] ?? null]
        === ['MISMATCH', 'ไม่พบใน VBRP, ยอดรวมไม่ตรง (PDF: 300.00 / ASN: 500.00)', ['pdf' => 'wrong', 'asn' => 'ok', 'vbrp' => 'na']],
    j($lines['93280008-1 8850000000081'] ?? null)
);
$summary = api('get_job_reconcile', [], ['job_id' => $engine])['data']['summary'] ?? [];
$jobRow = $jm->getJob($engine);
check(
    'the summary counts the lines waiting for the ASN, and the Job counters leave them out',
    [$summary['matched'] ?? null, $summary['pending_fix'] ?? null, $summary['pending_asn'] ?? null, $summary['mismatch'] ?? null] === [2, 0, 5, 2]
        && [(int)$jobRow['asn_matched_count'], (int)$jobRow['asn_pending_fix_count'], (int)$jobRow['asn_mismatch_count']] === [2, 0, 2],
    j([$summary, $jobRow['asn_matched_count'], $jobRow['asn_pending_fix_count'], $jobRow['asn_mismatch_count']])
);
$jm->clearVbrp($engine);
$plain = api('get_job_reconcile', [], ['job_id' => $engine, 'force' => 1])['data'] ?? [];
check(
    'without VBRP rows there is no PENDING_ASN and no summary key for it: the bills whose ASN alone was wrong are judged against the ASN again and are wrong',
    !array_key_exists('pending_asn', $plain['summary'] ?? ['pending_asn' => 1]) && !in_array('PENDING_ASN', array_column($plain['results'] ?? [], 'status'), true)
        && array_count_values(array_column($plain['results'] ?? [], 'status')) === ['MISMATCH' => 6, 'MATCH' => 3],
    j(array_map(function ($row) {
        return [$row['asn']['invoice_no'], $row['status'], $row['reason']];
    }, $plain['results'] ?? []))
);

// --- fix, print, dashboard and history on one Job
$flow = makeJob($jm, 'Flow',
    [
        ['93290001-1', '8850000000011', 1, 500.00, 21.00],
        ['93290002-1', '8850000000021', 1, 200.00, 14.00],
        ['93290002-2', '8850000000022', 1, 999.00, 7.00],
        ['93290003-1', '8850000000031', 1, 300.00, 21.00],
        ['93290004-1', '8850000000041', 1, 300.00, 21.00],
        ['93290005-1', '8850000000051', 1, 900.00, 21.00],
    ],
    [
        'a.pdf' => [[1, '93290001-2', '8850000000011', 1, '300.00', '21.00']],
        'b.pdf' => [[1, '93290002-2', '8850000000021', 1, '200.00', '14.00'], [2, '93290002-2', '8850000000022', 1, '100.00', '7.00']],
        'c.pdf' => [[1, '93290003-1', '8850000000031', 1, '300.00', '21.00']],
        'm.pdf' => [[1, '93290004-1', '8850000000041', 1, '300.00', '21.00']],
        'd.pdf' => [[1, '93290005-1', '8850000000051', 1, '700.00', '21.00']],
    ],
    [
        ['93290001-1', '8850000000011', 1, 300.00, 21.00],
        ['93290002-1', '8850000000021', 1, 200.00, 14.00],
        ['93290002-2', '8850000000022', 1, 100.00, 7.00],
        ['93290003-1', '8850000000031', 1, 300.00, 21.00],
        ['93290004-1', '8850000000041', 1, 300.00, 21.00],
        ['93290005-1', '8850000000051', 1, 500.00, 21.00],
    ]
);
$beforeStatuses = AsnReconciler::invoiceStatuses(array_values(gridLines($flow)));
check(
    'the Job holds one bill waiting for the fix, two waiting for the ASN (one alone in its file, one sharing its file with the bill waiting for the fix), two complete and one wrong',
    $beforeStatuses === ['93290001-1' => 'PENDING_ASN', '93290002-1' => 'PENDING_FIX', '93290002-2' => 'PENDING_ASN', '93290003-1' => 'MATCH', '93290004-1' => 'MATCH', '93290005-1' => 'MISMATCH']
        && array_unique(array_column(array_filter(gridLines($flow), function ($row) {
            return $row['status'] === 'PENDING_FIX';
        }), 'status_label')) === ['รอแก้ไข PDF'],
    j($beforeStatuses)
);
check('page loads, the dashboard and the print plan record no history', (function () use ($flow, $pdo) {
    api('get_job_reconcile', [], ['job_id' => $flow]);
    api('get_job_dashboard', [], ['job_id' => $flow]);
    api('print_plan', [], ['job_id' => $flow]);
    return historyRows($pdo, $flow) === [];
})(), j(historyRows($pdo, $flow)));
$compared = api('get_job_reconcile', [], ['job_id' => $flow, 'force' => 1, 'event' => 'compare']);
check(
    'the compare button records every bill once, with no earlier status and its problem as the detail',
    historyRows($pdo, $flow) === ['93290001-1 compare - PENDING_ASN', '93290002-1 compare - PENDING_FIX', '93290002-2 compare - PENDING_ASN', '93290003-1 compare - MATCH', '93290004-1 compare - MATCH', '93290005-1 compare - MISMATCH']
        && ((new BillHistory($pdo))->forJob($flow)['93290001-1'][0]['detail'] ?? null) === 'ASN ยอดก่อน VAT ไม่ตรง (ASN 500.00 / PDF 300.00 = VBRP 300.00)'
        && ((new BillHistory($pdo))->forJob($flow)['93290003-1'][0]['detail'] ?? null) === ''
        && ($compared['status'] ?? '') === 'success' && !in_array(true, array_map(function ($row) {
            return array_key_exists('was', $row);
        }, $compared['data']['results'] ?? []), true),
    j(historyRows($pdo, $flow))
);
api('get_job_reconcile', [], ['job_id' => $flow, 'force' => 1, 'event' => 'compare']);
check('comparing again with nothing changed records nothing', count(historyRows($pdo, $flow)) === 6, j(historyRows($pdo, $flow)));

$rowsBefore = itemInvoices($jm, $flow);
$refused = api('fix_invoice', ['job_id' => $flow, 'invoice_no' => '93290001-1']);
check(
    'fixing one bill that waits for the ASN is refused in the user\'s words and touches nothing',
    ($refused['status'] ?? '') === 'success' && ($refused['data'] ?? null) === ['success' => false, 'message' => 'บิลนี้รอแก้ไข ASN แก้ไข PDF ไม่ได้ ต้องแก้ ASN แล้วนำเข้าใหม่ก่อน']
        && itemInvoices($jm, $flow) === $rowsBefore && $jm->deleteSummary($flow)['fixed_bills'] === 0 && count(historyRows($pdo, $flow)) === 6,
    j($refused)
);
$fixed = api('fix_all_invoices', ['job_id' => $flow]);
$rowsAfter = itemInvoices($jm, $flow);
check(
    'the fix button leaves the file whose only bill waits for the ASN as it is and fixes the file of the bill waiting for the fix as a whole, the bill waiting for the ASN in it too',
    ($fixed['data']['success'] ?? null) === true && $rowsAfter['a.pdf p1 8850000000011'] === '93290001-2'
        && $rowsAfter['b.pdf p1 8850000000021'] === '93290002-1' && $rowsAfter['b.pdf p2 8850000000022'] === '93290002-2',
    j([$fixed['data'] ?? null, $rowsAfter])
);
$afterStatuses = AsnReconciler::invoiceStatuses(array_values($grid = gridLines($flow)));
check(
    'after the fix the bill that waited for the fix is complete and both bills waiting for the ASN still wait; the one in the fixed file has no note left about its PDF',
    $afterStatuses === ['93290001-1' => 'PENDING_ASN', '93290002-1' => 'MATCH', '93290002-2' => 'PENDING_ASN', '93290003-1' => 'MATCH', '93290004-1' => 'MATCH', '93290005-1' => 'MISMATCH']
        && ($grid['93290002-2 8850000000022']['sources']['notes'] ?? null) === []
        && ($grid['93290001-1 8850000000011']['sources']['notes'] ?? null) === ['หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: ' . $page],
    j([$afterStatuses, $grid['93290002-2 8850000000022']['sources']['notes'] ?? null])
);
check(
    'the fix records its change: the bill it fixed went from waiting for the fix to fixed, the others did not move',
    array_slice(historyRows($pdo, $flow), 6) === ['93290002-1 fix PENDING_FIX FIXED'],
    j(historyRows($pdo, $flow))
);
$grid = gridLines($flow);
$dash = api('get_job_dashboard', [], ['job_id' => $flow])['data'] ?? [];
$dashRows = [];
foreach ($dash['invoices'] ?? [] as $row) {
    $dashRows[$row['invoice_no']] = $row;
}
check(
    'a bill shows where it came from: the grid lines and the dashboard row of the fixed bill have was, the dashboard row its history with date and event; a bill with one record has history and no was',
    ($grid['93290002-1 8850000000021']['was'] ?? null) === ['PENDING_FIX'] && ($dashRows['93290002-1']['was'] ?? null) === ['PENDING_FIX']
        && array_map(function ($h) {
            return [$h['event'], $h['from'], $h['to'], (bool)preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $h['at'])];
        }, $dashRows['93290002-1']['history'] ?? []) === [['compare', null, 'PENDING_FIX', true], ['fix', 'PENDING_FIX', 'FIXED', true]]
        && array_keys($dashRows['93290002-1']['history'][0] ?? []) === ['at', 'event', 'from', 'to', 'detail']
        && !array_key_exists('was', $grid['93290001-1 8850000000011'] ?? ['was' => 1]) && !array_key_exists('was', $dashRows['93290001-1'] ?? ['was' => 1])
        && count($dashRows['93290001-1']['history'] ?? []) === 1,
    j([$dashRows['93290002-1'] ?? null])
);
check(
    'the dashboard counts the bills waiting for the ASN apart: a bill the fix worked on that waits for the ASN counts only there, the fixed one under fixed',
    [$dash['complete'] ?? null, $dash['needs_fix'] ?? null, $dash['not_fixed'] ?? null, $dash['fixed'] ?? null, $dash['pending_asn'] ?? null, $dash['error'] ?? null, $dash['error_after_fix'] ?? null] === [2, 1, 0, 1, 2, 1, 0]
        && ($dashRows['93290002-2']['fixed'] ?? null) === false && $jm->deleteSummary($flow)['fixed_bills'] === 2
        && [$dashRows['93290001-1']['status'] ?? '', $dashRows['93290001-1']['problem'] ?? '', $dashRows['93290001-1']['solution'] ?? ''] === ['PENDING_ASN', 'ASN ยอดก่อน VAT ไม่ตรง (ASN 500.00 / PDF 300.00 = VBRP 300.00)', AsnReconciler::FIX_ASN],
    j(array_diff_key($dash, ['invoices' => 1]))
);
$plan = api('print_plan', [], ['job_id' => $flow])['data'] ?? [];
$dashStatuses = array_map(function ($row) {
    return $row['status'];
}, $dashRows);
ksort($dashStatuses, SORT_NATURAL);
$gridCounts = array_count_values(AsnReconciler::invoiceStatuses(array_values($grid)));
check(
    'the grid, the dashboard, the print plan and the fix agree on every bill: the plan prints the complete ones and leaves out each waiting and wrong one under its own count',
    $dashStatuses === AsnReconciler::invoiceStatuses(array_values($grid))
        && [$plan['ready_count'] ?? null, $plan['skipped'] ?? null] === [3, ['pending_fix' => 0, 'mismatch' => 1, 'printed' => 0, 'pending_asn' => 2]]
        && [$gridCounts['MATCH'], $gridCounts['PENDING_ASN'], $gridCounts['MISMATCH']] === [3, 2, 1],
    j([$dashStatuses, $plan])
);

$noAsn = makeJob($jm, 'No ASN wait', [['93290101-1', '8850000000011', 1, 300.00, 21.00]], ['x.pdf' => [[1, '93290101-1', '8850000000011', 1, '300.00', '21.00']]], [['93290101-1', '8850000000011', 1, 300.00, 21.00]]);
check('a print plan with no bill waiting for the ASN has no pending_asn key', array_keys(api('print_plan', [], ['job_id' => $noAsn])['data']['skipped'] ?? []) === ['pending_fix', 'mismatch', 'printed']);

$printer = new PrintManager($jm);
$ready = $printer->plan($flow)['ready'];
$pdo->prepare("UPDATE job_asn_records SET total_cost_ex_vat = 450 WHERE job_id = :job_id AND invoice_no = '93290004-1'")->execute([':job_id' => $flow]);
$started = api('print_start', ['job_id' => $flow])['data'] ?? [];
$planned = json_decode((string)@file_get_contents($jm->getJobPrintDir($flow, false) . DIRECTORY_SEPARATOR . '.tmp_' . ($started['token'] ?? '') . DIRECTORY_SEPARATOR . 'plan.json'), true);
check(
    'a bill that turns to waiting for the ASN between the plan and the start of a print is not copied',
    isset($ready['93290004-1']) && ($started['total'] ?? null) === 2 && array_column($planned ?: [], 'invoice') === ['93290002-1', '93290003-1']
        && ($started['plan']['skipped']['pending_asn'] ?? null) === 3,
    j([$started, $planned])
);
$pdo->prepare("UPDATE job_asn_records SET total_cost_ex_vat = 300 WHERE job_id = :job_id AND invoice_no = '93290004-1'")->execute([':job_id' => $flow]);
$jm->deleteDirectoryRecursive($jm->getJobPrintDir($flow, false) . DIRECTORY_SEPARATOR . '.tmp_' . ($started['token'] ?? 'none'));

$corrected = api('import_asn', [
    'job_id' => $flow, 'asn_filename' => 'corrected.xlsx [s]', 'append' => 1, 'reconcile' => 1, 'duplicate_mode' => 'replace',
    'records' => json_encode(asnRecords([['93290002-2', '8850000000022', 1, 100.00, 7.00]])),
]);
$correctedLine = null;
foreach ($corrected['reconcile']['results'] ?? [] as $row) {
    if ($row['asn']['invoice_no'] === '93290002-2') {
        $correctedLine = $row;
    }
}
check(
    'importing the corrected ASN takes the bill out of waiting for the ASN to fixed (the fix worked on its file), records it as asn_import, and the answer already shows where it came from',
    array_slice(historyRows($pdo, $flow), 7) === ['93290002-2 asn_import PENDING_ASN FIXED']
        && [$correctedLine['status'] ?? '', $correctedLine['fixed'] ?? null, $correctedLine['was'] ?? null] === ['MATCH', true, ['PENDING_ASN']],
    j([historyRows($pdo, $flow), $correctedLine])
);
api('job_vbrp_clear', ['job_id' => $flow]);
check(
    'clearing VBRP records the bills it moves: the bill whose ASN amount is wrong is judged with two sources again and is wrong',
    array_slice(historyRows($pdo, $flow), 8) === ['93290001-1 vbrp_clear PENDING_ASN MISMATCH'],
    j(historyRows($pdo, $flow))
);

// The real multipart path of the page for job_vbrp_import: php -S on a free port with the same throwaway root
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__)),
    [['pipe', 'r'], ['file', getenv('PDF_READER_ROOT') . '/server.out', 'w'], ['file', getenv('PDF_READER_ROOT') . '/server.err', 'w']],
    $pipes,
    dirname(__DIR__),
    getenv(),
    ['bypass_shell' => true]
);
$up = false;
for ($try = 0; $try < 50 && !$up; $try++) {
    usleep(100000);
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($socket) {
        fclose($socket);
        $up = true;
    }
}
$boundary = 'split' . bin2hex(random_bytes(6));
// The same VBRP rows as before the clear: VBRP is the reference (2026-11-10), so a bill it leaves out would turn wrong
// (ไม่พบใน VBRP) and move too; with every bill covered only the bill VBRP decides moves
$book = file_get_contents(vbrpBook('again.xlsx', [
    ['93290001-1', '8850000000011', 1, 300.00, 21.00],
    ['93290002-1', '8850000000021', 1, 200.00, 14.00],
    ['93290002-2', '8850000000022', 1, 100.00, 7.00],
    ['93290003-1', '8850000000031', 1, 300.00, 21.00],
    ['93290004-1', '8850000000041', 1, 300.00, 21.00],
    ['93290005-1', '8850000000051', 1, 500.00, 21.00],
]));
$imported = $up ? json_decode((string)@file_get_contents("http://127.0.0.1:$port/api.php?action=job_vbrp_import", false, stream_context_create(['http' => [
    'method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n", 'ignore_errors' => true, 'timeout' => 30,
    'content' => "--$boundary\r\nContent-Disposition: form-data; name=\"job_id\"\r\n\r\n$flow\r\n--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"again.xlsx\"\r\nContent-Type: application/octet-stream\r\n\r\n$book\r\n--$boundary--\r\n",
]])), true) : null;
proc_terminate($server);
proc_close($server);
$grid = gridLines($flow);
check(
    'importing VBRP records the bills it moves (as the page posts it), and a bill that was waiting and wrong shows both, first seen first',
    ($imported['status'] ?? '') === 'success' && array_slice(historyRows($pdo, $flow), 9) === ['93290001-1 vbrp_import MISMATCH PENDING_ASN']
        && ($grid['93290001-1 8850000000011']['was'] ?? null) === ['PENDING_ASN', 'MISMATCH'],
    $up ? j([$imported, historyRows($pdo, $flow)]) : 'php -S did not start on port ' . $port
);

$asnIds = array_column(array_filter($jm->getAsnRecords($flow), function ($r) {
    return $r['invoice_no'] === '93290003-1';
}), 'id');
$deleted = api('delete_asn_records', ['job_id' => $flow, 'ids' => json_encode($asnIds)]);
check(
    'deleting ASN rows undoes the compare (as before), so nothing is recorded until the compare button is pressed again',
    ($deleted['status'] ?? '') === 'success' && ($deleted['asn_reconciled'] ?? null) === 0 && count(historyRows($pdo, $flow)) === 10,
    j([$deleted['asn_reconciled'] ?? null, historyRows($pdo, $flow)])
);
api('get_job_reconcile', [], ['job_id' => $flow, 'force' => 1, 'event' => 'compare']);
check('the next compare records nothing for bills that did not move, and the deleted bill has no new row', count(historyRows($pdo, $flow)) === 10, j(historyRows($pdo, $flow)));

$pdo->exec("CREATE TRIGGER split_test_no_history BEFORE INSERT ON job_bill_history BEGIN SELECT RAISE(ABORT, 'history is down'); END");
$pdo->prepare("UPDATE job_asn_records SET total_cost_ex_vat = 300 WHERE job_id = :job_id AND invoice_no = '93290001-1'")->execute([':job_id' => $flow]);
$stillWorks = api('get_job_reconcile', [], ['job_id' => $flow, 'force' => 1, 'event' => 'compare']);
$fixStillWorks = api('fix_all_invoices', ['job_id' => $flow]);
$pdo->exec('DROP TRIGGER split_test_no_history');
check(
    'a history that cannot be written never fails the action: the compare and the fix answer as usual',
    ($stillWorks['status'] ?? '') === 'success' && count($stillWorks['data']['results'] ?? []) === 5 && ($fixStillWorks['data']['success'] ?? null) === true
        && count(historyRows($pdo, $flow)) === 10,
    j([$stillWorks['status'] ?? null, $stillWorks['message'] ?? null, $fixStillWorks])
);

$fresh = makeJob($jm, 'Fresh', [['93290201-1', '8850000000011', 1, 300.00, 21.00]], ['y.pdf' => [[1, '93290201-1', '8850000000011', 1, '300.00', '21.00']]], []);
check(
    'a Job never compared records nothing on an action, and the compare starts its history',
    $jm->recordBillHistory($fresh, 'fix') === 0 && $jm->recordBillHistory($fresh, 'asn_import') === 0 && historyRows($pdo, $fresh) === []
        && $jm->recordBillHistory($fresh, 'compare') === 1 && historyRows($pdo, $fresh) === ['93290201-1 compare - MATCH'] && $jm->recordBillHistory($fresh, 'compare') === 0,
    j(historyRows($pdo, $fresh))
);
$history = new BillHistory($pdo);
$unknown = '';
try {
    $history->record($fresh, 'page_load', ['93290201-1' => ['category' => 'MISMATCH', 'detail' => '']]);
} catch (InvalidArgumentException $e) {
    $unknown = $e->getMessage();
}
$history->record($fresh, 'fix', ['93290201-1' => ['category' => 'MISMATCH', 'detail' => str_repeat('ยอด', 150)]]);
$long = (new BillHistory($pdo))->forJob($fresh)['93290201-1'][1]['detail'] ?? '';
check(
    'only the six events are recorded, and a detail keeps its first 300 characters',
    $unknown !== '' && preg_match_all('/./u', $long) === 300 && $long === str_repeat('ยอด', 100),
    j([$unknown, strlen($long)])
);
check(
    'was lists the waiting and wrong categories before the latest row, first seen first, each once',
    BillHistory::was([['to' => 'PENDING_ASN'], ['to' => 'MATCH'], ['to' => 'PENDING_FIX'], ['to' => 'PENDING_ASN'], ['to' => 'FIXED']]) === ['PENDING_ASN', 'PENDING_FIX']
        && BillHistory::was([['to' => 'PENDING_FIX'], ['to' => 'MISMATCH']]) === ['PENDING_FIX'] && BillHistory::was([['to' => 'MISMATCH']]) === []
);

$jm->clearJobData($flow);
check('clearing the Tax Invoice data of a Job deletes its history', historyRows($pdo, $flow) === []);
$pdo->prepare('DELETE FROM jobs WHERE id = :id')->execute([':id' => $fresh]);
check('the history of a Job goes with its row (foreign key)', historyRows($pdo, $fresh) === []);
api('get_job_reconcile', [], ['job_id' => $noAsn, 'force' => 1, 'event' => 'compare']);
$noAsnJob = $jm->getJob($noAsn);
api('delete_job', ['job_id' => $noAsn, 'confirm_name' => $noAsnJob['name']]);
check('deleting a Job deletes its history', $noAsnJob !== null && $jm->getJob($noAsn) === null && historyRows($pdo, $noAsn) === []);

$quiet = makeJob($jm, 'Quiet', [['93290301-1', '8850000000011', 1, 500.00, 21.00]], ['z.pdf' => [[1, '93290301-1', '8850000000011', 1, '300.00', '21.00']]], [['93290301-1', '8850000000011', 1, 300.00, 21.00]]);
$quietLines = gridLines($quiet);
$quietDash = api('get_job_dashboard', [], ['job_id' => $quiet])['data'] ?? [];
check(
    'a Job without history has neither was nor history anywhere',
    !array_key_exists('was', $quietLines['93290301-1 8850000000011'] ?? ['was' => 1])
        && !array_key_exists('was', $quietDash['invoices'][0] ?? ['was' => 1]) && !array_key_exists('history', $quietDash['invoices'][0] ?? ['history' => 1]),
    j($quietDash['invoices'][0] ?? null)
);
$fixer = new PdfFixer($pdo);
check(
    'fixJob answers success false for a bill that waits for the ASN, its number given with spaces around it',
    $fixer->fixJob($quiet, ' 93290301-1 ') === ['success' => false, 'message' => PdfFixer::WAITS_FOR_ASN]
);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
