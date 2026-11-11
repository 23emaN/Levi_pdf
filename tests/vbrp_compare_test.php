<?php
// Run: php tests/vbrp_compare_test.php
// VBRP as the third source of the Tax Invoice compare: SourceVerdict, VbrpFigures, the upload import (job_vbrp_import)
// and the engine, grid, print plan and dashboard on small Jobs. Runs against a throwaway root (PDF_READER_ROOT), never
// touches data/ or myfile/. The workbooks are written here, so no sample file is needed.

$mode = $argv[1] ?? '';

if ($mode === '--api') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = ['action' => $request['action']] + $request['get'];
    $_POST = $request['post'];
    require __DIR__ . '/../api.php';
    exit;
}

if ($mode !== '--run') {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_vbrp3_' . bin2hex(random_bytes(4));
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
use App\Database;
use App\JobManager;
use App\SourceVerdict;
use App\VbrpFigures;
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
    $requestFile = tempnam(sys_get_temp_dir(), 'vbrp3_req_');
    file_put_contents($requestFile, json_encode(['action' => $action, 'post' => $post, 'get' => $get]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($requestFile));
    unlink($requestFile);
    return json_decode($out, true) ?: [];
}

function j($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

/**
 * Writes a workbook of sheets, each given as [name => [row number => [column letter => value]]]: a string as a shared
 * string, an int or float as a number
 */
function writeWorkbook(string $path, array $sheets): void
{
    $strings = [];
    $sheetXml = [];
    foreach (array_values($sheets) as $index => $rows) {
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
        $sheetXml[$index + 1] = $xml . '</sheetData></worksheet>';
    }
    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach ($strings as $string) {
        $shared .= '<si><t>' . htmlspecialchars($string) . '</t></si>';
    }
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach (array_keys($sheets) as $index => $name) {
        $workbook .= '<sheet name="' . htmlspecialchars($name) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        $rels .= '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
    }
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', $workbook . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', $shared . '</sst>');
    foreach ($sheetXml as $number => $xml) {
        $zip->addFromString('xl/worksheets/sheet' . $number . '.xml', $xml);
    }
    $zip->close();
}

/**
 * A VBRP sheet: the report's header rows (row 3 with the labels the import checks) and one row per
 * [invoice, barcode, pieces, Subtotal 2, Subtotal 4, Tax Amount, Subtotal 3 or null]
 */
function vbrpSheet(array $rows, array $labels = VbrpStore::LABELS): array
{
    $sheet = [1 => ['A' => 'VBRP'], 2 => ['C' => 'Invoice', 'H' => 'Quantity'], 3 => $labels];
    foreach (array_values($rows) as $i => [$invoice, $barcode, $pieces, $price, $cost, $vat, $subtotal3]) {
        $sheet[4 + $i] = ['A' => 'C' . $i, 'C' => $invoice, 'E' => '51000000' . $i, 'H' => $pieces, 'CI' => $price, 'CJ' => $subtotal3, 'CK' => $cost, 'CQ' => $barcode, 'EM' => $vat];
    }
    return $sheet;
}

function vbrpBook(string $name, array $sheets): string
{
    global $work;
    $path = $work . DIRECTORY_SEPARATOR . $name;
    writeWorkbook($path, $sheets);
    return $path;
}

/**
 * A Job of small bills, each on a PDF of its own: ASN rows [invoice, barcode, pieces, Selling Price, Total Cost Ex VAT,
 * Total VAT], PDF rows [invoice, barcode, pieces, Amt Excl VAT, VAT] and VBRP rows as vbrpSheet takes them
 */
function threeWayJob(JobManager $jm, string $label, array $asn, array $pdf, array $vbrp): int
{
    $jobId = (int)$jm->createJob($label)['id'];
    $jm->saveAsnRecords($jobId, 'asn.xlsx [s]', array_map(function ($a) {
        return ['invoice_no' => $a[0], 'ean_upc' => $a[1], 'quantity' => $a[2], 'selling_price' => $a[3], 'total_cost_ex_vat' => $a[4], 'total_vat' => $a[5], 'dest_store_no' => '10166', 'delivery_no' => ''];
    }, $asn));
    $files = [];
    foreach ($pdf as [$invoice, $barcode, $pieces, $excl, $vat]) {
        $files[$invoice][] = [
            'tax_invoice' => $invoice, 'page_no' => 1, 'barcode' => $barcode, 'units' => $pieces, 'amount' => 100, 'total_amount' => 'Null',
            'amt_excl_vat' => $excl, 'vat_amount' => $vat, 'ship_to_party' => '', 'address' => '',
        ];
    }
    $entries = [];
    foreach ($files as $invoice => $items) {
        $entries[] = ['filename' => $invoice . '.pdf', 'storedPath' => '', 'items' => $items];
    }
    $jm->recordExtractedBatch($jobId, $entries, true);
    if ($vbrp) {
        $jm->importVbrp($jobId, vbrpBook("job{$jobId}.xlsx", ['ZFP' => vbrpSheet($vbrp)]), "job{$jobId}.xlsx");
    }
    return $jobId;
}

/** The compare lines of a Job as the grid gets them: "invoice barcode" => row */
function gridLines(int $jobId): array
{
    $lines = [];
    foreach (api('get_job_reconcile', [], ['job_id' => $jobId, 'force' => 1])['data']['results'] ?? [] as $row) {
        $lines[$row['asn']['invoice_no'] . ' ' . $row['asn']['ean_upc']] = $row;
    }
    return $lines;
}

/** [ready, waiting for the fix, waiting for the ASN, wrong] bills of the print plan and of the grid's worst line per bill */
function printAndGrid(int $jobId): array
{
    $plan = api('print_plan', [], ['job_id' => $jobId])['data'] ?? [];
    $statuses = AsnReconciler::invoiceStatuses(array_values(gridLines($jobId)));
    $grid = array_count_values($statuses) + ['MATCH' => 0, 'PENDING_FIX' => 0, 'PENDING_ASN' => 0, 'MISMATCH' => 0];
    return [
        [$plan['ready_count'] ?? null, $plan['skipped']['pending_fix'] ?? null, $plan['skipped']['pending_asn'] ?? 0, $plan['skipped']['mismatch'] ?? null],
        [$grid['MATCH'], $grid['PENDING_FIX'], $grid['PENDING_ASN'], $grid['MISMATCH']],
    ];
}

function brief(array $row): array
{
    return [$row['status'], $row['reason'], $row['sources']['overall'] ?? null, $row['sources']['notes'] ?? null];
}

// --- SourceVerdict::judge
$ok = SourceVerdict::OK;
$wrong = SourceVerdict::WRONG;
$na = SourceVerdict::NA;
$table = [
    'three equal' => [[5, 5, 5, 0.0], [$ok, $ok, $ok]],
    'the PDF alone differs' => [[4, 2, 2, 0.0], [$wrong, $ok, $ok]],
    'the ASN alone differs' => [[2, 4, 2, 0.0], [$ok, $wrong, $ok]],
    'VBRP alone differs' => [[2, 2, 4, 0.0], [$ok, $ok, $wrong]],
    'all three differ' => [[1, 2, 3, 0.0], [$wrong, $wrong, $wrong]],
    'PDF and ASN only, differing: the PDF is wrong' => [[1, 2, null, 0.0], [$wrong, $ok, $na]],
    'PDF and VBRP only, differing: the PDF is wrong' => [[1, null, 2, 0.0], [$wrong, $na, $ok]],
    'ASN and VBRP only, differing: the ASN is wrong' => [[null, 1, 2, 0.0], [$na, $wrong, $ok]],
    'two present and equal' => [[null, 7, 7, 0.0], [$na, $ok, $ok]],
    'one present: nothing judged' => [[null, 7, null, 0.0], [$na, $na, $na]],
    'none present' => [[null, null, null, 1.0], [$na, $na, $na]],
    'within ฿1.00: 4.15 - 3.15 is a float hair above 1.00 and still agrees' => [[4.15, 3.15, 3.15, 1.0], [$ok, $ok, $ok]],
    'within ฿0.15: 1.07 - 0.92 is a float hair above 0.15 and still agrees' => [[1.07, 0.92, 0.92, 0.15], [$ok, $ok, $ok]],
    '฿1.01 away is wrong' => [[13028.06, 13027.05, 13027.05, 1.0], [$wrong, $ok, $ok]],
    'pairwise, not transitive: 100 / 101 / 102 are all ok' => [[100, 101, 102, 1.0], [$ok, $ok, $ok]],
    'pairwise: 100 / 101 / 102.01 leaves VBRP alone' => [[100, 101, 102.01, 1.0], [$ok, $ok, $wrong]],
    'VAT: 0.15 apart agrees, 0.16 does not' => [[52.65, 52.50, 52.34, 0.15], [$ok, $ok, $wrong]],
    'zero is a figure: 0 pieces against 0' => [[0, 2, 0, 0.0], [$ok, $wrong, $ok]],
];
$judged = [];
foreach ($table as $label => [[$p, $a, $v, $tolerance], $want]) {
    $got = array_values(SourceVerdict::judge($p, $a, $v, $tolerance));
    if ($got !== $want) {
        $judged[$label] = $got;
    }
}
check('judge: a source equal to another present source within the tolerance is ok, else wrong; two that differ leave the later of VBRP, ASN, PDF wrong; fewer than two judge nothing; pairs are judged in satang and one by one', $judged === [], j($judged));
check(
    'the checks are the user\'s three with the tolerances of AsnReconciler: pieces exact, Amt Excl VAT ฿1.00, VAT ฿0.15; the selling price is none of them',
    array_column(SourceVerdict::CHECKS, 'tolerance') === [0.0, 1.0, 0.15] && array_keys(SourceVerdict::CHECKS) === ['qty', 'amount', 'vat'],
    j(SourceVerdict::CHECKS)
);

// --- VbrpFigures
$figures = VbrpFigures::fromRows([
    ['invoice' => '93250512-1', 'ean_upc' => '5401221356849', 'quantity' => 2, 'selling_price' => 500, 'total_cost_ex_vat' => 600.10, 'total_vat' => 42.01, 'subtotal_3' => -178.5],
    ['invoice' => '93250512-1', 'ean_upc' => '5401221356849', 'quantity' => 1, 'selling_price' => 480, 'total_cost_ex_vat' => 300.05, 'total_vat' => 21.00, 'subtotal_3' => -1.01],
    ['invoice' => '93250512-1', 'ean_upc' => '5401221356849', 'quantity' => 1, 'selling_price' => 999, 'total_cost_ex_vat' => 999, 'total_vat' => 70, 'subtotal_3' => -1.0],
    ['invoice' => '93250512-1', 'ean_upc' => '5401221356856', 'quantity' => 1, 'selling_price' => 999, 'total_cost_ex_vat' => 999, 'total_vat' => 70, 'subtotal_3' => 0],
    ['invoice' => '93250512-1', 'ean_upc' => '5401221356863', 'quantity' => 1, 'selling_price' => 999, 'total_cost_ex_vat' => 999, 'total_vat' => 70, 'subtotal_3' => 3.5],
    ['invoice' => ' 93250512-1', 'ean_upc' => '5.401221356870', 'quantity' => 4, 'selling_price' => null, 'total_cost_ex_vat' => 10, 'total_vat' => 0.7, 'subtotal_3' => null],
    ['invoice' => '93250599-1', 'ean_upc' => '5401221356849', 'quantity' => 1, 'selling_price' => 1, 'total_cost_ex_vat' => 1, 'total_vat' => 1, 'subtotal_3' => 0],
]);
$key = App\DashboardBuilder::invoiceKey('93250512-1');
check(
    'only rows with Subtotal 3 below -1 count, and rows stored without it: -1.01 counts, -1, 0 and a positive one do not; the sums are rounded to satang',
    $figures->bill($key) === ['rows' => 3, 'cost' => 910.15, 'vat' => 63.71, 'lines' => 2]
        && $figures->line($key, '5401221356849') === ['qty' => 3.0, 'price' => 480.0]
        && !$figures->lists($key, '5401221356856') && !$figures->lists($key, '5401221356863'),
    j([$figures->bill($key), $figures->line($key, '5401221356849')])
);
check(
    'a line is keyed by the barcode digits, keeps the lowest Subtotal 2 or none; a barcode the bill does not list has 0 pieces and no price',
    $figures->line($key, '5401221356870') === ['qty' => 4.0, 'price' => null] && $figures->line($key, '8850000000000') === ['qty' => 0.0, 'price' => null],
    j($figures->line($key, '5401221356870'))
);
check(
    'a bill whose rows are all left out is not covered; no row at all is empty',
    $figures->covers($key) && !$figures->covers(App\DashboardBuilder::invoiceKey('93250599-1')) && VbrpFigures::fromRows([])->isEmpty() && !$figures->isEmpty()
);
$oldDb = new PDO('sqlite:' . getenv('PDF_READER_ROOT') . '/old_vbrp.sqlite');
$oldDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
check('a database without the VBRP tables has no figures', VbrpFigures::forJob($oldDb, 1)->isEmpty());
$oldDb->exec('CREATE TABLE job_vbrp_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, file_id INTEGER NOT NULL, filename TEXT NOT NULL, sheet TEXT NOT NULL, row_no INTEGER NOT NULL,
    combine TEXT, invoice TEXT, delivery_no TEXT, quantity REAL, selling_price REAL, total_cost_ex_vat REAL, ean_upc TEXT, total_vat REAL)');
$oldDb->exec("INSERT INTO job_vbrp_rows (job_id, file_id, filename, sheet, row_no, invoice, quantity, selling_price, total_cost_ex_vat, ean_upc, total_vat) VALUES (1, 1, 'old.xlsx', 'ZFP', 4, '93250512-1', 2, 500, 600, '5401221356849', 42)");
VbrpStore::createTables($oldDb);
VbrpStore::createTables($oldDb);
$oldFigures = VbrpFigures::forJob($oldDb, 1);
check(
    'a database made before Subtotal 3 was kept gains the column, twice without harm, and its rows count',
    in_array('subtotal_3', array_column($oldDb->query('PRAGMA table_info(job_vbrp_rows)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
        && $oldFigures->bill($key) === ['rows' => 1, 'cost' => 600.0, 'vat' => 42.0, 'lines' => 1]
);
$oldDb = null;

// --- the import
$job = $jm->createJob('Import');
$jobId = (int)$job['id'];
$jobFolder = $jm->getJobFolderDir($job, false);
check('a Job with ASN rows and no VBRP row waits for its VBRP file before the compare', $jm->initialAsnStep(['asn_filename' => 'ASN.xlsx'] + $job) === 'vbrp');

$first = vbrpBook('first.xlsx', [
    'Notes' => [3 => ['A' => 'Combine', 'C' => 'Remark']],
    'ZFP' => vbrpSheet([
        ['93260001-1', '5401221356849', 1, 500, 300, 21, -10],
        ['93260001-1', '5401221356856', 2, 250, 300, 21, -10],
        ['93260002-1', '5401221356849', 3, 500, 900, 63, null],
    ]),
]);
$imported = $jm->importVbrp($jobId, $first, 'C:\\fakepath\\first.xlsx');
check(
    'importing reads the sheets whose row 3 has the VBRP labels, lists the others, and answers what it stored',
    $imported === ['filename' => 'first.xlsx', 'rows' => 3, 'invoices' => 2, 'replaced_invoices' => [], 'replaced_rows' => 0, 'ignored_sheets' => ['Notes'],
        'vbrp_counts' => ['files' => 1, 'rows' => 3, 'invoices' => 2]],
    j($imported)
);
$stored = $jm->getVbrpRows($jobId);
check(
    'the rows keep Subtotal 3 (CJ) with the other columns, empty when the cell is',
    array_column($stored, 'subtotal_3') === [-10.0, -10.0, null] && $stored[1]['ean_upc'] === '5401221356856' && $stored[2]['total_cost_ex_vat'] === 900.0,
    j($stored)
);
check(
    'with VBRP rows the Job may be compared; the status counts carry the VBRP files, rows and bills',
    $jm->initialAsnStep(['asn_filename' => 'ASN.xlsx'] + $job) === 'compare'
        && array_slice($jm->statusCounts($jobId), 4) === ['vbrp_files' => 1, 'vbrp_rows' => 3, 'vbrp_invoices' => 2],
    j($jm->statusCounts($jobId))
);
$again = $jm->importVbrp($jobId, $first, 'first.xlsx');
check(
    'the same file again replaces its rows, not doubles them',
    $again['replaced_invoices'] === ['93260001-1', '93260002-1'] && $again['replaced_rows'] === 3 && $again['vbrp_counts'] === ['files' => 1, 'rows' => 3, 'invoices' => 2],
    j($again)
);
$second = vbrpBook('second.xlsx', ['ZFP' => vbrpSheet([['93260002-1', '5401221356849', 5, 500, 1500, 105, -10], ['93260003-1', '5401221356849', 1, 500, 300, 21, -10]])]);
$replaced = $jm->importVbrp($jobId, $second, 'second.xlsx');
$byInvoice = [];
foreach ($jm->getVbrpRows($jobId) as $row) {
    $byInvoice[$row['invoice']][] = [$row['filename'], $row['quantity']];
}
check(
    'another file replaces the rows of every invoice it holds and keeps the others',
    $replaced['replaced_invoices'] === ['93260002-1'] && $replaced['replaced_rows'] === 1
        && $byInvoice === ['93260001-1' => [['first.xlsx', 1.0], ['first.xlsx', 2.0]], '93260002-1' => [['second.xlsx', 5.0]], '93260003-1' => [['second.xlsx', 1.0]]]
        && $replaced['vbrp_counts'] === ['files' => 2, 'rows' => 4, 'invoices' => 3],
    j([$replaced, $byInvoice])
);

$refusals = [];
$wrongHeader = vbrpBook('asn.xlsx', ['CDS' => vbrpSheet([['93260009-1', '5401221356849', 1, 1, 1, 1, -10]], ['A' => 'Combine', 'C' => 'Invoice No'] + VbrpStore::LABELS)]);
$noData = vbrpBook('empty.xlsx', ['ZFP' => vbrpSheet([])]);
$broken = vbrpBook('broken.xlsx', []);
file_put_contents($broken, 'not a zip');
$cases = [
    'wrong header' => [$wrongHeader, 'asn.xlsx', UPLOAD_ERR_OK, 'ไม่ใช่ไฟล์ VBRP (ช่อง C3 ควรเป็น "Bill" แต่เป็น "Invoice No")'],
    'a CSV' => [$first, 'first.csv', UPLOAD_ERR_OK, 'รับเฉพาะไฟล์ Excel (.xlsx)'],
    'an Excel lock file' => [$first, '~$first.xlsx', UPLOAD_ERR_OK, 'รับเฉพาะไฟล์ Excel (.xlsx)'],
    'too large for PHP' => [$first, 'first.xlsx', UPLOAD_ERR_INI_SIZE, 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (upload_max_filesize ใน php.ini)'],
    'not a workbook' => [$broken, 'broken.xlsx', UPLOAD_ERR_OK, 'ไม่ใช่ไฟล์ Excel (.xlsx) หรือไฟล์เสียหาย'],
    'no data row' => [$noData, 'empty.xlsx', UPLOAD_ERR_OK, 'ไฟล์นี้ไม่มีแถวข้อมูล VBRP ตั้งแต่แถวที่ 4'],
];
foreach ($cases as $label => [$path, $name, $error, $message]) {
    try {
        $jm->importVbrp($jobId, $path, $name, $error);
        $refusals[$label] = 'accepted';
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== $message) {
            $refusals[$label] = $e->getMessage();
        }
    }
}
check(
    'a file that is not a VBRP report is refused with a Thai message and changes nothing: wrong row 3 (naming the cell), not .xlsx, a lock file, an upload error, a broken file, no data row',
    $refusals === [] && $jm->vbrpCounts($jobId) === ['files' => 2, 'rows' => 4, 'invoices' => 3],
    j($refusals)
);
$unknownJob = '';
try {
    $jm->importVbrp(999999, $first, 'first.xlsx');
} catch (RuntimeException $e) {
    $unknownJob = $e->getMessage();
}
check('an unknown Job is refused', $unknownJob === 'ไม่พบ Job นี้', $unknownJob);
check('the import never makes a VBRP folder', !file_exists($jobFolder . '/VBRP') && !file_exists($jobFolder . '/TAX INVOICE/VBRP'), j(scandir($jobFolder)));
$noFile = api('job_vbrp_import', ['job_id' => $jobId]);
check('job_vbrp_import without a file answers the upload error', ($noFile['status'] ?? '') === 'error' && ($noFile['message'] ?? '') === 'อัปโหลดไฟล์ไม่สำเร็จ (รหัส 4)', j($noFile));

$refusals = [
    'a request PHP emptied for post_max_size' => [VbrpStore::sizeRefusal(2516582, true, UPLOAD_ERR_OK, '2M', '2M'),
        'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไฟล์ประมาณ 2.4 MB แต่ php.ini ตั้ง upload_max_filesize = 2M และ post_max_size = 2M) ให้เพิ่มสองค่านี้ใน php.ini แล้วเปิดเว็บเซิร์ฟเวอร์ใหม่'],
    'a file over upload_max_filesize' => [VbrpStore::sizeRefusal(3145728, false, UPLOAD_ERR_INI_SIZE, '8M', '2M'),
        'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไฟล์ประมาณ 3.0 MB แต่ php.ini ตั้ง upload_max_filesize = 2M และ post_max_size = 8M) ให้เพิ่มสองค่านี้ใน php.ini แล้วเปิดเว็บเซิร์ฟเวอร์ใหม่'],
    'an empty request' => [VbrpStore::sizeRefusal(0, true, UPLOAD_ERR_NO_FILE, '8M', '2M'), null],
    'a request that arrived' => [VbrpStore::sizeRefusal(2516582, false, UPLOAD_ERR_OK, '8M', '2M'), null],
];
check('an upload PHP refused for its size is told in Thai with its size and both php.ini limits; an empty or a received request is not', array_filter($refusals, function ($case) {
    return $case[0] !== $case[1];
}) === [], j($refusals));

// The real multipart path of the page: php -S on a free port with the same throwaway root and small upload limits
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open(
    escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=32K -d post_max_size=64K -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__)),
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
/** The answer of job_vbrp_import to a multipart POST of job_id and one file, as the page sends it; raw text when not JSON */
function postFile(bool $up, int $port, int $jobId, string $name, string $content)
{
    if (!$up) {
        return null;
    }
    $boundary = 'vbrp' . bin2hex(random_bytes(6));
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"job_id\"\r\n\r\n$jobId\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$name\"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n"
        . $content . "\r\n--$boundary--\r\n";
    $text = (string)@file_get_contents("http://127.0.0.1:$port/api.php?action=job_vbrp_import", false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 30,
    ]]));
    return json_decode($text, true) ?? $text;
}

$answer = postFile($up, $port, $jobId, 'third.xlsx', file_get_contents(vbrpBook('third.xlsx', ['ZFP' => vbrpSheet([['93260004-1', '5401221356849', 1, 500, 300, 21, -10]])])));
$overFile = postFile($up, $port, $jobId, 'big.xlsx', str_repeat('x', 60 * 1024));
$overPost = postFile($up, $port, $jobId, 'bigger.xlsx', str_repeat('x', 100 * 1024));
proc_terminate($server);
proc_close($server);
check(
    'job_vbrp_import takes the file the page posts as multipart and answers status, file name, rows, bills, what it replaced, the sheets it left out and the VBRP counts',
    $answer === ['status' => 'success', 'filename' => 'third.xlsx', 'rows' => 1, 'invoices' => 1, 'replaced_invoices' => [], 'replaced_rows' => 0, 'ignored_sheets' => [],
        'vbrp_counts' => ['files' => 3, 'rows' => 5, 'invoices' => 4]],
    $up ? j($answer) : 'php -S did not start on port ' . $port
);
check(
    'a file over upload_max_filesize, and a request over post_max_size that PHP empties, are answered as too large naming both limits, not as an unknown Job',
    ($overFile['message'] ?? '') === 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไฟล์ประมาณ 0.1 MB แต่ php.ini ตั้ง upload_max_filesize = 32K และ post_max_size = 64K) ให้เพิ่มสองค่านี้ใน php.ini แล้วเปิดเว็บเซิร์ฟเวอร์ใหม่'
        && ($overPost['message'] ?? '') === 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไฟล์ประมาณ 0.1 MB แต่ php.ini ตั้ง upload_max_filesize = 32K และ post_max_size = 64K) ให้เพิ่มสองค่านี้ใน php.ini แล้วเปิดเว็บเซิร์ฟเวอร์ใหม่'
        && ($overPost['status'] ?? '') === 'error' && $jm->vbrpCounts($jobId)['rows'] === 5,
    j([$overFile, $overPost])
);

$jm->saveAsnRecords($jobId, 'asn.xlsx [s]', [['invoice_no' => '93260001-1', 'ean_upc' => '5401221356849', 'quantity' => 1, 'selling_price' => 1, 'total_cost_ex_vat' => 1, 'total_vat' => 1, 'dest_store_no' => '', 'delivery_no' => '']]);
check('the delete summary counts the VBRP rows of the Job', ($jm->deleteSummary($jobId)['vbrp_rows'] ?? null) === 5, j($jm->deleteSummary($jobId)));
$jm->clearJobData($jobId);
check('clearing the data of the Job clears its VBRP rows and files with its ASN rows', $jm->vbrpCounts($jobId) === ['files' => 0, 'rows' => 0, 'invoices' => 0] && $jm->asnCounts($jobId)['rows'] === 0);

// --- the engine
$asnWrong = threeWayJob($jm, 'ASN wrong',
    [['93270001-1', '8850000000011', 1, 100, 1000.00, 52.50], ['93270001-1', '8850000000012', 2, 50, 1000.00, 52.50]],
    [['93270001-1', '8850000000011', 1, '750.00', '52.50'], ['93270001-1', '8850000000012', 2, '750.00', '52.50']],
    [['93270001-1', '8850000000011', 1, 100, 500.00, 26.25, -10], ['93270001-1', '8850000000012', 2, 50, 250.00, 26.25, -10]]
);
$lines = gridLines($asnWrong);
$line = $lines['93270001-1 8850000000011'] ?? [];
$asnAmount = 'ASN ยอดก่อน VAT ไม่ตรง (ASN 1,000.00 / PDF 750.00 = VBRP 750.00)';
check(
    'a bill whose ASN amount is wrong while the PDF equals VBRP waits for the ASN on every line: the problem names the ASN figure and the two that agree, the action is to import a corrected ASN, the PDF is not wrong and the problem is no note too',
    brief($line) === ['PENDING_ASN', $asnAmount, ['pdf' => 'ok', 'asn' => 'wrong', 'vbrp' => 'ok'], []]
        && [$line['status_label'] ?? '', $line['action'] ?? '', $line['can_fix'] ?? null] === ['รอแก้ไข ASN', AsnReconciler::FIX_ASN, false]
        && brief($lines['93270001-1 8850000000012'] ?? []) === ['PENDING_ASN', $asnAmount, ['pdf' => 'ok', 'asn' => 'wrong', 'vbrp' => 'ok'], []],
    j([brief($line), $line['action'] ?? null])
);
check(
    'the line carries the VBRP figures, its selling price among them, and the verdict of each check, the selling price not one; amount_ok keeps telling PDF against ASN',
    ($line['sources']['vbrp'] ?? null) == ['qty' => 1, 'price' => 100, 'amount' => 750, 'vat' => 52.5]
        && ($line['sources']['verdict'] ?? null) === ['qty' => ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'ok'],
            'amount' => ['pdf' => 'ok', 'asn' => 'wrong', 'vbrp' => 'ok'], 'vat' => ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'ok']]
        && ($line['amount_ok'] ?? null) === false,
    j($line['sources'] ?? null)
);
$agree = printAndGrid($asnWrong);
check('the print plan leaves that bill out as waiting for the ASN, as the grid shows it', $agree === [[0, 0, 1, 0], [0, 0, 1, 0]], j($agree));
$jm->clearVbrp($asnWrong);
$twoWay = gridLines($asnWrong)['93270001-1 8850000000011'] ?? [];
check(
    'without its VBRP rows the same bill is wrong on the amount again, and no line has sources',
    [$twoWay['status'] ?? '', $twoWay['reason'] ?? ''] === ['MISMATCH', 'ยอดรวมไม่ตรง (PDF: 750.00 / ASN: 1,000.00)'] && !array_key_exists('sources', $twoWay)
        && !isset(api('get_job_reconcile', [], ['job_id' => $asnWrong])['data']['summary']['vbrp']),
    j($twoWay)
);

$pdfWrong = threeWayJob($jm, 'PDF wrong',
    [['93270002-1', '8850000000021', 2, 100, 1000.00, 70.00], ['93270002-1', '8850000000022', 1, 100, 1000.00, 70.00]],
    [['93270002-1', '8850000000021', 1, '750.00', '52.50'], ['93270002-1', '8850000000022', 1, '750.00', '52.50']],
    [['93270002-1', '8850000000021', 2, 100, 600.00, 40.00, -10], ['93270002-1', '8850000000022', 1, 100, 400.00, 30.00, -10]]
);
$lines = gridLines($pdfWrong);
$three = 'ยอดรวมไม่ตรง (PDF: 750.00 / ASN: 1,000.00 / VBRP: 1,000.00), VAT ไม่ตรง (PDF: ฿52.50 / ASN: ฿70.00 / VBRP: ฿70.00)';
check(
    'a PDF wrong against ASN and VBRP that agree is wrong; its problems name the three figures, and the line right itself names the culprit as before',
    brief($lines['93270002-1 8850000000021'] ?? []) === ['MISMATCH', 'จำนวนไม่ตรง (PDF: 1 ชิ้น / ASN: 2 ชิ้น / VBRP: 2 ชิ้น), ' . $three, ['pdf' => 'wrong', 'asn' => 'ok', 'vbrp' => 'ok'], []]
        && ($lines['93270002-1 8850000000022']['reason'] ?? '') === 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 8850000000021 จำนวนไม่ตรง (PDF: 1 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง (' . $three . ')',
    j(array_map('brief', $lines))
);

$vbrpWrong = threeWayJob($jm, 'VBRP wrong',
    [['93270003-1', '8850000000031', 1, 100, 750.00, 52.50]],
    [['93270003-1', '8850000000031', 1, '750.00', '52.50']],
    [['93270003-1', '8850000000031', 2, 150.00, 760.00, 52.70, -10]]
);
$vbrpLine = gridLines($vbrpWrong)['93270003-1 8850000000031'] ?? [];
check(
    'a PDF equal to the ASN is complete when VBRP differs, and every VBRP fault is a note naming the other two figures; a selling price ฿50 apart is shown, not compared',
    brief($vbrpLine) === ['MATCH', AsnReconciler::COMPLETE, ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'wrong'], [
        'VBRP ไม่ตรง: จำนวน 2 ชิ้น (PDF: 1 ชิ้น / ASN: 1 ชิ้น)',
        'VBRP ไม่ตรง: ยอดก่อน VAT 760.00 (PDF: 750.00 / ASN: 750.00)',
        'VBRP ไม่ตรง: VAT ฿52.70 (PDF: ฿52.50 / ASN: ฿52.50)',
    ]] && ($vbrpLine['sources']['vbrp']['price'] ?? null) == 150,
    j([brief($vbrpLine), $vbrpLine['sources']['vbrp'] ?? null])
);

$threeAmounts = threeWayJob($jm, 'Three amounts',
    [['93270013-1', '8850000000131', 2, 100, 31364.53, 1310.00]],
    [['93270013-1', '8850000000131', 2, '22594.56', '1302.17']],
    [['93270013-1', '8850000000131', 2, 100, 18602.26, 1302.17, -10]]
);
$threeLine = gridLines($threeAmounts)['93270013-1 8850000000131'] ?? [];
check(
    'a check that judges the PDF wrong gives no note, its problem names the three figures (all three amounts differ: ASN and VBRP stay wrong); a check the PDF is right on keeps its note on that wrong line',
    brief($threeLine) === ['MISMATCH', 'ยอดรวมไม่ตรง (PDF: 22,594.56 / ASN: 31,364.53 / VBRP: 18,602.26)', ['pdf' => 'wrong', 'asn' => 'wrong', 'vbrp' => 'wrong'],
        ['ASN ไม่ตรง: VAT ฿1,310.00 (PDF: ฿1,302.17 / VBRP: ฿1,302.17)']]
        && ($threeLine['sources']['verdict']['amount'] ?? null) === ['pdf' => 'wrong', 'asn' => 'wrong', 'vbrp' => 'wrong'],
    j([brief($threeLine), $threeLine['sources']['verdict'] ?? null])
);

$mixed = threeWayJob($jm, 'Uncovered',
    [['93270004-1', '8850000000041', 1, -5, 750.00, 52.50], ['93270005-1', '8850000000051', 1, -5, 1000.00, 52.50]],
    [['93270004-1', '8850000000041', 1, '750.00', '52.50'], ['93270005-1', '8850000000051', 1, '750.00', '52.50']],
    [['93270004-1', '8850000000041', 1, 20, 750.00, 52.50, -10], ['93270005-1', '8850000000051', 1, 20, 750.00, 52.50, -1]]
);
$lines = gridLines($mixed);
check(
    'a bill VBRP does not cover (its only row has Subtotal 3 of -1) is marked MISMATCH (ไม่พบใน VBRP) with 2-way PDF/ASN verdicts; in a covered bill a negative ASN price, compared with nothing, fails nothing and is no note',
    brief($lines['93270005-1 8850000000051'] ?? [])
        === ['MISMATCH', 'ไม่พบใน VBRP, Selling Price ติดลบใน ASN (฿-5.00), ยอดรวมไม่ตรง (PDF: 750.00 / ASN: 1,000.00)', ['pdf' => 'wrong', 'asn' => 'wrong', 'vbrp' => 'na'], []]
        && brief($lines['93270004-1 8850000000041'] ?? []) === ['MATCH', AsnReconciler::COMPLETE, ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'ok'], []]
        && (api('get_job_reconcile', [], ['job_id' => $mixed])['data']['summary']['vbrp'] ?? null) === ['covered_bills' => 1, 'uncovered_bills' => 1],
    j(array_map('brief', $lines))
);

$barcodes = threeWayJob($jm, 'Barcodes',
    [['93270006-1', '8850000000061', 1, 100, 300.00, 21.00], ['93270006-1', '8850000000062', 1, 100, 300.00, 21.00],
        ['93270007-1', '8850000000071', 1, 100, 300.00, 21.00], ['93270007-1', '8850000000072', 1, 100, 300.00, 21.00]],
    [['93270006-1', '8850000000061', 1, '300.00', '21.00'], ['93270006-1', '8850000000069', 2, '300.00', '21.00'],
        ['93270007-1', '8850000000071', 1, '200.00', '14.00']],
    [['93270006-1', '8850000000061', 1, 100, 100.00, 7.00, -10], ['93270006-1', '8850000000069', 2, 100, 200.00, 14.00, -10],
        ['93270007-1', '8850000000071', 1, 100, 200.00, 14.00, -10], ['93270007-1', '8850000000072', 1, 100, 100.00, 7.00, -10]]
);
$lines = gridLines($barcodes);
$lacks = 'ASN ไม่มีบาร์โค้ด 8850000000069 (2 ชิ้น) ที่ PDF และ VBRP มี';
$lacksProblem = 'บาร์โค้ด 8850000000062: ASN จำนวน ไม่ตรง (ASN 1 ชิ้น / PDF 0 ชิ้น = VBRP 0 ชิ้น), ' . $lacks;
check(
    'a barcode the PDF prints with the pieces VBRP lists, which the ASN lacks, is no PDF problem but an ASN fault of the bill, and so is an ASN line neither the PDF nor VBRP has: every line waits for the ASN, the quantity named with its barcode first, the missing barcode last, no note repeats them',
    brief($lines['93270006-1 8850000000061'] ?? []) === ['PENDING_ASN', $lacksProblem, ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'ok'], []]
        && brief($lines['93270006-1 8850000000062'] ?? []) === ['PENDING_ASN', $lacksProblem, ['pdf' => 'ok', 'asn' => 'wrong', 'vbrp' => 'ok'], []]
        && array_key_exists('pdf', $lines['93270006-1 8850000000062'] ?? []) && $lines['93270006-1 8850000000062']['pdf'] === null,
    j(array_map('brief', $lines))
);
check(
    'an ASN line the PDF lacks while VBRP bills it stays wrong as before, and makes the other line of its bill name it',
    [$lines['93270007-1 8850000000072']['status'] ?? '', $lines['93270007-1 8850000000072']['reason'] ?? ''] === ['MISMATCH', 'มีรายการในไฟล์ ASN แต่ไม่พบไฟล์ PDF ของบิลนี้ [PDF ไม่ออก หรือบิลตกหล่น]']
        && ($lines['93270007-1 8850000000071']['reason'] ?? '') === 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 8850000000072 ไม่พบใน PDF จึงทำให้ยอดรวมบิลไม่ตรง ('
            . 'ยอดรวมไม่ตรง (PDF: 200.00 / ASN: 300.00 / VBRP: 300.00), VAT ไม่ตรง (PDF: ฿14.00 / ASN: ฿21.00 / VBRP: ฿21.00))',
    j(array_map('brief', $lines))
);
$extra = threeWayJob($jm, 'PDF only',
    [['93270008-1', '8850000000081', 1, 100, 300.00, 21.00]],
    [['93270008-1', '8850000000081', 1, '300.00', '21.00'], ['93270008-1', '8850000000089', 1, '300.00', '21.00']],
    [['93270008-1', '8850000000081', 1, 100, 300.00, 21.00, -10]]
);
check(
    'a barcode of the PDF that neither ASN nor VBRP lists stays the PDF problem it was',
    brief(gridLines($extra)['93270008-1 8850000000081'] ?? [])[0] === 'MISMATCH'
        && (gridLines($extra)['93270008-1 8850000000081']['reason'] ?? '') === 'บาร์โค้ด 8850000000089 มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น)',
    j(brief(gridLines($extra)['93270008-1 8850000000081'] ?? []))
);

$skips = threeWayJob($jm, 'Subtotal 3',
    [['93270009-1', '8850000000091', 1, 100, 300.00, 21.00], ['93270010-1', '8850000000101', 1, 100, 300.00, 21.00]],
    [['93270009-1', '8850000000091', 1, '300.00', '21.00'], ['93270010-1', '8850000000101', 1, '300.00', '21.00']],
    [['93270009-1', '8850000000091', 1, 100, 300.00, 21.00, -10], ['93270009-1', '8850000000091', 1, 100, 300.00, 21.00, 0],
        ['93270010-1', '8850000000101', 1, 100, 300.00, 21.00, -10], ['93270010-1', '8850000000101', 1, 100, 300.00, 21.00, -1.01]]
);
$lines = gridLines($skips);
check(
    'a VBRP row with Subtotal 3 of 0 is not counted, one of -1.01 is: the duplicate at 0 changes nothing, the one at -1.01 doubles VBRP and only VBRP is wrong',
    ($lines['93270009-1 8850000000091']['sources']['overall'] ?? null) === ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'ok']
        && ($lines['93270010-1 8850000000101']['sources']['overall'] ?? null) === ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'wrong']
        && ($lines['93270010-1 8850000000101']['sources']['vbrp'] ?? null) == ['qty' => 2, 'price' => 100, 'amount' => 600, 'vat' => 42]
        && $lines['93270010-1 8850000000101']['status'] === 'MATCH',
    j(array_map('brief', $lines))
);

$waits = threeWayJob($jm, 'Waits for the fix',
    [['93270011-1', '8850000000111', 1, 100, 300.00, 21.00]],
    [['93270011-2', '8850000000111', 1, '300.00', '21.00']],
    [['93270011-1', '8850000000111', 1, 100, 300.00, 21.00, -10]]
);
$waiting = gridLines($waits)['93270011-1 8850000000111'] ?? [];
check(
    'a line that only waits for the fix button (its page prints another number of the bill) is not wrong in its PDF: the plan judged its figures, the pages are what wait',
    ($waiting['status'] ?? '') === 'PENDING_FIX' && ($waiting['sources']['overall'] ?? null) === ['pdf' => 'ok', 'asn' => 'ok', 'vbrp' => 'ok'],
    j(brief($waiting))
);

// --- the dashboard
$dash = api('get_job_dashboard', [], ['job_id' => $barcodes])['data'] ?? [];
$rows = [];
$billFigures = [];
foreach ($dash['invoices'] ?? [] as $row) {
    $rows[$row['invoice_no']] = [$row['status'], $row['verdict'], $row['notes']];
    $billFigures[$row['invoice_no']] = $row['vbrp'];
}
check(
    'each bill of the dashboard has the worst verdict of each source over its lines, its VBRP figures and its notes, a note of one line among several naming its barcode',
    $rows === [
        '93270006-1' => ['PENDING_ASN', ['pdf' => 'ok', 'asn' => 'wrong', 'vbrp' => 'ok'], []],
        '93270007-1' => ['MISMATCH', ['pdf' => 'wrong', 'asn' => 'ok', 'vbrp' => 'ok'], []],
    ] && $billFigures == ['93270006-1' => ['cost' => 300, 'vat' => 21, 'lines' => 2], '93270007-1' => ['cost' => 300, 'vat' => 21, 'lines' => 2]]
        && ($dash['verdict_counts'] ?? null) ===['pdf' => ['ok' => 1, 'wrong' => 1, 'na' => 0], 'asn' => ['ok' => 1, 'wrong' => 1, 'na' => 0], 'vbrp' => ['ok' => 2, 'wrong' => 0, 'na' => 0]]
        && [$dash['complete'] ?? null, $dash['pending_asn'] ?? null, $dash['error'] ?? null] === [0, 1, 1],
    j([$rows, $billFigures, $dash['verdict_counts'] ?? null])
);
$agree = printAndGrid($barcodes);
check('the print plan, the grid and the dashboard count the same ready, ASN-waiting and wrong bills', $agree === [[0, 0, 1, 1], [0, 0, 1, 1]] && [$dash['complete'], $dash['pending_asn'], $dash['error']] === [0, 1, 1], j($agree));
$many = threeWayJob($jm, 'Many notes',
    [['93270011-1', '8850000000111', 1, 10, 300.00, 21.00], ['93270011-1', '8850000000112', 1, 20, 300.00, 21.00], ['93270011-1', '8850000000113', 1, 30, 300.00, 21.00]],
    [['93270011-1', '8850000000111', 1, '300.00', '21.00'], ['93270011-1', '8850000000112', 1, '300.00', '21.00'], ['93270011-1', '8850000000113', 1, '300.00', '21.00']],
    [['93270011-1', '8850000000111', 2, 15, 300.00, 21.00, -10], ['93270011-1', '8850000000112', 3, 25, 300.00, 21.00, -10], ['93270011-1', '8850000000113', 4, 35, 300.00, 21.00, -10]]
);
gridLines($many);
$manyNotes = (api('get_job_dashboard', [], ['job_id' => $many])['data']['invoices'][0]['notes'] ?? null);
check(
    'the dashboard shows three notes of a bill, the last followed by the count of the rest; the selling prices ASN and VBRP disagree on make none',
    $manyNotes === ['บาร์โค้ด 8850000000111: VBRP ไม่ตรง: จำนวน 2 ชิ้น (PDF: 1 ชิ้น / ASN: 1 ชิ้น)', 'VBRP ไม่ตรง: ยอดก่อน VAT 900.00 (PDF: 300.00 / ASN: 300.00)',
        'VBRP ไม่ตรง: VAT ฿63.00 (PDF: ฿21.00 / ASN: ฿21.00) และอีก 2 ประเด็น'],
    j($manyNotes)
);
$uncoveredDash = api('get_job_dashboard', [], ['job_id' => $mixed])['data'] ?? [];
$plainDash = api('get_job_dashboard', [], ['job_id' => $asnWrong])['data'] ?? [];
check(
    'a bill VBRP does not cover has 2-way PDF/ASN verdicts with vbrp na, no VBRP figures and no note; a Job without VBRP rows has none of the new keys',
    ($uncoveredDash['invoices'][1]['verdict'] ?? null) === ['pdf' => 'wrong', 'asn' => 'wrong', 'vbrp' => 'na']
        && array_key_exists('vbrp', $uncoveredDash['invoices'][1] ?? []) && $uncoveredDash['invoices'][1]['vbrp'] === null
        && ($uncoveredDash['invoices'][1]['notes'] ?? null) === [] && array_key_exists('verdict_counts', $uncoveredDash)
        && !array_key_exists('verdict_counts', $plainDash) && !array_key_exists('verdict', $plainDash['invoices'][0] ?? ['verdict' => 1]),
    j([$uncoveredDash['invoices'] ?? null, array_keys($plainDash)])
);
$noneCovered = threeWayJob($jm, 'None covered',
    [['93270012-1', '8850000000121', 1, 100, 300.00, 21.00]],
    [['93270012-1', '8850000000121', 1, '300.00', '21.00']],
    [['93279999-1', '8850000000121', 1, 100, 300.00, 21.00, -10]]
);
gridLines($noneCovered);
$noneDash = api('get_job_dashboard', [], ['job_id' => $noneCovered])['data'] ?? [];
check('with VBRP rows that cover none of its bills the dashboard says so: verdict_counts is null', array_key_exists('verdict_counts', $noneDash) && $noneDash['verdict_counts'] === null, j($noneDash));

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
