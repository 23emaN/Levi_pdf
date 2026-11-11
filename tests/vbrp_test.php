<?php
// Run: php tests/vbrp_test.php
// The VBRP page: the XLSX reader, the files and rows of a Job in the VBRP folders, the API actions and vbrp.php /
// job.php rendered through the PHP CLI. Runs against a throwaway root (PDF_READER_ROOT), never touches data/ or myfile/.
// The workbooks are written here, so no sample file is needed.

$mode = $argv[1] ?? '';

if ($mode === '--api') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = ['action' => $request['action']] + $request['get'];
    $_POST = $request['post'];
    require __DIR__ . '/../api.php';
    exit;
}

if ($mode === '--page') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = $request['get'];
    require __DIR__ . '/../' . $request['page'];
    exit;
}

if ($mode !== '--run') {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_vbrp_' . bin2hex(random_bytes(4));
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

use App\JobManager;
use App\VbrpStore;
use App\XlsxReader;

$jm = new JobManager();
$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function runSelf(string $mode, array $request): string
{
    $requestFile = tempnam(sys_get_temp_dir(), 'vbrp_req_');
    file_put_contents($requestFile, json_encode($request));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " $mode " . escapeshellarg($requestFile));
    unlink($requestFile);
    return $out;
}

function api(string $action, array $post = [], array $get = []): array
{
    return json_decode(runSelf('--api', ['action' => $action, 'post' => $post, 'get' => $get]), true) ?: [];
}

function page(string $file, array $get): string
{
    return runSelf('--page', ['page' => $file, 'get' => $get]);
}

function plainText(string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

/**
 * Writes a workbook of sheets, each given as [name => [row number => [column letter => value]]]. A string value is
 * written as a shared string, except one starting with "inline:" (inline string); an int or float as a number.
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
                $ref = $column . $rowNo;
                if (is_string($value) && strpos($value, 'inline:') === 0) {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars(substr($value, 7)) . '</t></is></c>';
                } elseif (is_string($value)) {
                    $strings[] = $value;
                    $xml .= '<c r="' . $ref . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $sheetXml[$index + 1] = $xml . '</sheetData></worksheet>';
    }

    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach ($strings as $string) {
        // the phonetic guide of a string is not part of its text
        $shared .= '<si><t>' . htmlspecialchars($string) . '</t><rPh sb="0" eb="1"><t>ignored</t></rPh></si>';
    }
    $shared .= '</sst>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach (array_keys($sheets) as $index => $name) {
        $workbook .= '<sheet name="' . htmlspecialchars($name) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        $rels .= '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
    }
    $workbook .= '</sheets></workbook>';
    $rels .= '</Relationships>';

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/sharedStrings.xml', $shared);
    foreach ($sheetXml as $number => $xml) {
        $zip->addFromString('xl/worksheets/sheet' . $number . '.xml', $xml);
    }
    $zip->close();
}

$header = [
    1 => ['A' => 'VBRP'],
    2 => ['A' => 'Combine', 'C' => 'Invoice', 'E' => 'Delivery No.', 'H' => 'Quantity', 'CI' => 'Selling Price', 'CK' => 'Total Cost EX VAT', 'CQ' => 'EAN/UPC', 'EM' => 'Total VAT'],
    3 => ['A' => 'Combine', 'C' => 'Bill', 'E' => 'Bill. Doc.', 'H' => 'Billed Quantity'],
];
$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vbrp_book_' . bin2hex(random_bytes(4));
mkdir($workDir);
$bookA = $workDir . DIRECTORY_SEPARATOR . 'a.xlsx';
writeWorkbook($bookA, [
    'ZFP' => $header + [
        4 => ['A' => '51008996525401221356849', 'B' => 'not read', 'C' => '93250512-1', 'E' => 5100899652, 'H' => 1, 'CI' => 1011.5, 'CK' => 680.64, 'CQ' => 5401221356849, 'EM' => 47.64],
        5 => ['A' => 'inline:combine two', 'C' => 'inline:93250519-1', 'H' => 2.5, 'CI' => 10, 'CQ' => '5.401221356849E+12'],
        6 => ['B' => 'only a column that is not read'],
        7 => [],
        8 => ['C' => '93250520-1', 'H' => 'abc', 'EM' => 7],
    ],
    'Sheet2' => $header,
    'Second' => $header + [4 => ['C' => '93250599-1', 'H' => 3, 'EM' => 1.25]],
]);

// --- the reader
$sheets = XlsxReader::sheets($bookA);
check('the reader lists the sheets in workbook order', array_column($sheets, 'name') === ['ZFP', 'Sheet2', 'Second'], json_encode(array_column($sheets, 'name')));
$cells = [];
foreach (XlsxReader::rows($bookA, $sheets[0]['entry'], array_keys(VbrpStore::COLUMNS), 4) as $rowNo => $row) {
    $cells[$rowNo] = $row;
}
check(
    'the reader gives the chosen columns of the rows from row 4 down, skipping the header rows, empty rows and rows holding none of the columns',
    array_keys($cells) === [4, 5, 8],
    json_encode(array_keys($cells))
);
check(
    'shared strings (without their phonetic guide), inline strings and numbers come back as text; a column without a cell is not given',
    $cells[4]['A'] === '51008996525401221356849' && $cells[4]['C'] === '93250512-1' && $cells[4]['CK'] === '680.64'
        && $cells[5]['A'] === 'combine two' && $cells[5]['C'] === '93250519-1' && !isset($cells[4]['B']) && !array_key_exists('E', $cells[5]),
    json_encode($cells[5])
);

$read = VbrpStore::readFile($bookA);
check(
    'a file is read into rows of fields for every sheet, a sheet without data rows has none',
    count($read) === 3 && count($read[0]['rows']) === 3 && count($read[1]['rows']) === 0 && count($read[2]['rows']) === 1,
    json_encode(array_map('count', array_column($read, 'rows')))
);
check(
    'text fields keep their text (an EAN saved as 5.4E+12 gets its digits), amounts are numbers and text in an amount column is empty',
    $read[0]['rows'][0]['ean_upc'] === '5401221356849' && $read[0]['rows'][1]['ean_upc'] === '5401221356849'
        && $read[0]['rows'][0]['selling_price'] === 1011.5 && $read[0]['rows'][1]['quantity'] === 2.5
        && $read[0]['rows'][2]['quantity'] === null && $read[0]['rows'][2]['invoice'] === '93250520-1' && $read[0]['rows'][0]['row_no'] === 4,
    json_encode($read[0]['rows'][2])
);

// --- the Job
$job = $jm->createJob('Vbrp <A&B>');
$jobId = (int)$job['id'];
$upload = $jm->getJobVbrpUploadDir($job);
$finished = $jm->getJobVbrpFinishedDir($job);
check(
    'the VBRP folders of a Job are VBRP/upload here and VBRP/finished inside the Job folder',
    basename(dirname($upload)) === 'VBRP' && basename($upload) === 'upload here' && basename($finished) === 'finished'
        && dirname(dirname($upload)) === $jm->getJobFolderDir($job, false),
    $upload
);

$bookB = $workDir . DIRECTORY_SEPARATOR . 'b.xlsx';
writeWorkbook($bookB, [
    'ZFP' => $header + [
        4 => ['A' => '51008997005401221356849', 'C' => '93260001-1', 'E' => 5100899700, 'H' => 1, 'CI' => 500, 'CK' => 300, 'CQ' => 5401221356849, 'EM' => 21],
        5 => ['A' => 'inline:combine B2', 'C' => 'inline:93260002-1', 'H' => 2, 'CI' => 20, 'CQ' => '5401221356849'],
        8 => ['C' => '93260003-1', 'H' => 4, 'EM' => 9],
    ],
    'Second' => $header + [4 => ['C' => '93260099-1', 'H' => 5, 'EM' => 2.5]],
]);

copy($bookA, $upload . '/a.xlsx');
mkdir($upload . '/sub');
copy($bookB, $upload . '/sub/b.xlsx');
file_put_contents($upload . '/~$a.xlsx', 'lock');
file_put_contents($upload . '/note.txt', 'text');
writeWorkbook($upload . '/empty.xlsx', ['ZFP' => $header]);
file_put_contents($upload . '/broken.xlsx', 'not a zip');

$scan = $jm->scanVbrp($jobId);
check(
    'the scan lists the .xlsx files of upload here, subfolders included, and leaves out lock files and other types',
    $scan['total_in_upload'] === 4 && $scan['new_count'] === 4 && $scan['existing_count'] === 0
        && array_column($scan['new_files'], 'rel_path') === ['a.xlsx', 'broken.xlsx', 'empty.xlsx', 'sub/b.xlsx'],
    json_encode(array_column($scan['new_files'], 'rel_path'))
);

$result = $jm->extractVbrp($jobId, array_column($scan['new_files'], 'rel_path'), false);
check(
    'reading the files records the readable ones and moves them to finished; an empty workbook and a broken file stay in upload here with the reason',
    $result['processed_files'] === 2 && $result['rows_count'] === 8 && $result['no_data'] === ['empty.xlsx']
        && count($result['failed']) === 1 && $result['failed'][0]['filename'] === 'broken.xlsx'
        && is_file($finished . '/a.xlsx') && is_file($finished . '/b.xlsx') && !is_file($upload . '/a.xlsx') && !is_dir($upload . '/sub')
        && is_file($upload . '/empty.xlsx') && is_file($upload . '/broken.xlsx'),
    json_encode($result, JSON_UNESCAPED_UNICODE)
);

$rows = $jm->getVbrpRows($jobId);
check(
    'the rows of every sheet of the files are held in the order read, with their sheet, file and Excel row',
    count($rows) === 8 && $rows[0]['filename'] === 'a.xlsx' && $rows[0]['sheet'] === 'ZFP' && $rows[0]['row_no'] === 4 && $rows[0]['invoice'] === '93250512-1'
        && $rows[0]['total_cost_ex_vat'] === 680.64 && $rows[3]['sheet'] === 'Second' && $rows[4]['filename'] === 'b.xlsx',
    json_encode(array_map(function ($r) {
        return [$r['filename'], $r['sheet'], $r['row_no']];
    }, $rows))
);
check('the counts are the files, rows and invoices of the Job', $jm->vbrpCounts($jobId) === ['files' => 2, 'rows' => 8, 'invoices' => 8], json_encode($jm->vbrpCounts($jobId)));

copy($bookA, $upload . '/a.xlsx');
$again = $jm->scanVbrp($jobId);
check('a file whose name was read before is an existing file in the scan', $again['existing_count'] === 1 && $again['existing_files'][0]['filename'] === 'a.xlsx', json_encode($again['existing_files']));
$skip = $jm->extractVbrp($jobId, ['a.xlsx'], false);
check(
    'an existing file is skipped (and stays in upload here) unless it is read again to replace its rows',
    $skip['processed_files'] === 0 && $skip['skipped_existing'] === ['a.xlsx'] && is_file($upload . '/a.xlsx') && count($jm->getVbrpRows($jobId)) === 8
);
$replace = $jm->extractVbrp($jobId, ['a.xlsx'], true);
check(
    'reading it again to replace gives the file its rows once, not twice',
    $replace['processed_files'] === 1 && $replace['replaced_files'] === ['a.xlsx'] && count($jm->getVbrpRows($jobId)) === 8 && $jm->vbrpCounts($jobId)['files'] === 2
);

// --- Column C (invoice) duplicate replacement across different files
$bookC = $workDir . DIRECTORY_SEPARATOR . 'c.xlsx';
writeWorkbook($bookC, [
    'ZFP' => $header + [
        4 => ['A' => 'NEW-COMBINE-512', 'C' => '93250512-1', 'E' => 5100999999, 'H' => 99, 'CI' => 9999, 'CK' => 8888, 'CQ' => 5401221356849, 'EM' => 622.16],
    ],
]);
copy($bookC, $upload . '/c.xlsx');
$overlap = $jm->extractVbrp($jobId, ['c.xlsx'], true);
$afterOverlap = $jm->getVbrpRows($jobId);
$inv512Rows = array_values(array_filter($afterOverlap, function ($r) {
    return $r['invoice'] === '93250512-1';
}));
check(
    'uploading a new file whose Column C (invoice) matches an existing row deletes the old row of that invoice and inserts the new one',
    $overlap['processed_files'] === 1 && $overlap['replaced_invoices'] === ['93250512-1'] && $overlap['replaced_rows'] === 1
        && count($afterOverlap) === 8 && count($inv512Rows) === 1 && $inv512Rows[0]['filename'] === 'c.xlsx' && $inv512Rows[0]['quantity'] === 99.0
        && $jm->vbrpCounts($jobId) === ['files' => 3, 'rows' => 8, 'invoices' => 8],
    json_encode([$overlap, $inv512Rows], JSON_UNESCAPED_UNICODE)
);

// Re-uploading a.xlsx restores 93250512-1 to a.xlsx and removes c.xlsx (since c.xlsx has no rows left)
copy($bookA, $upload . '/a.xlsx');
$restoreA = $jm->extractVbrp($jobId, ['a.xlsx'], true);
check(
    'when a new file replaces every invoice of an earlier file, the earlier file is removed from the active finished list',
    in_array('c.xlsx', $restoreA['replaced_files'], true) && $jm->vbrpCounts($jobId) === ['files' => 2, 'rows' => 8, 'invoices' => 8]
        && array_column($jm->getVbrpFiles($jobId), 'filename') === ['b.xlsx', 'a.xlsx'],
    json_encode($jm->getVbrpFiles($jobId), JSON_UNESCAPED_UNICODE)
);

$outside = $workDir . DIRECTORY_SEPARATOR . 'outside.xlsx';
copy($bookA, $outside);
$escape = $jm->extractVbrp($jobId, ['../../../../' . basename($workDir) . '/outside.xlsx'], false);
check('a path outside the VBRP folders reads nothing', $escape['processed_files'] === 0 && $escape['failed'] === [] && $jm->vbrpCounts($jobId)['rows'] === 8);

// --- API
$apiScan = api('job_vbrp_scan', [], ['job_id' => $jobId]);
check('job_vbrp_scan answers the scan', ($apiScan['status'] ?? '') === 'success' && ($apiScan['data']['total_in_upload'] ?? null) === 2, json_encode($apiScan, JSON_UNESCAPED_UNICODE));
$apiRows = api('job_vbrp_rows', [], ['job_id' => $jobId]);
check('job_vbrp_rows answers the rows and the counts', ($apiRows['count'] ?? null) === 8 && ($apiRows['counts'] ?? null) === ['files' => 2, 'rows' => 8, 'invoices' => 8], json_encode($apiRows['counts'] ?? null));
$apiExtract = api('job_vbrp_extract_batch', ['job_id' => $jobId, 'files' => json_encode(['empty.xlsx'])]);
check('job_vbrp_extract_batch answers what it did with each file', ($apiExtract['status'] ?? '') === 'success' && ($apiExtract['no_data'] ?? null) === ['empty.xlsx'], json_encode($apiExtract, JSON_UNESCAPED_UNICODE));
$apiUnknown = api('job_vbrp_rows', [], ['job_id' => 999999]);
check('an unknown Job is an error answer', ($apiUnknown['status'] ?? '') === 'error', json_encode($apiUnknown, JSON_UNESCAPED_UNICODE));

// --- pages
$vbrpPage = page('vbrp.php', ['job_id' => $jobId]);
preg_match('/id="vbrp-table">\s*<thead>(.*?)<\/thead>/s', $vbrpPage, $head);
preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $ths);
preg_match_all('/<th\b[^>]*title="Excel column ([A-Z]+)"/', $head[1] ?? '', $letters);
check(
    'vbrp.php heads the table with the chosen columns in order, and the letters of its headers are the ones the store reads',
    array_map('plainText', $ths[1]) === ['#', 'Combine', 'Invoice', 'Delivery No.', 'Quantity', 'Selling Price', 'Total Cost EX VAT', 'EAN/UPC', 'Total VAT', 'Source']
        && $letters[1] === array_keys(VbrpStore::COLUMNS),
    json_encode([array_map('plainText', $ths[1]), $letters[1]], JSON_UNESCAPED_UNICODE)
);
check(
    'vbrp.php says it reads from row 4 down, from the columns of the store',
    strpos(plainText($vbrpPage), 'ระบบจะอ่านข้อมูลตั้งแต่ แถวที่ 4 ลงไป จากคอลัมน์ A / C / E / H / CI / CK / CQ / EM') !== false
);
$vbrpNoComments = preg_replace('/<!--.*?-->/s', '', $vbrpPage);
check(
    'vbrp.php has the toolbar of the other pages: extract and stop, the two folders, search, Excel and clear',
    count(array_filter(['vbrp-start', 'vbrp-stop', 'vbrp-open-upload', 'vbrp-open-finished', 'vbrp-finished-menu', 'vbrp-search', 'vbrp-export', 'vbrp-clear'], function ($id) use ($vbrpNoComments) {
        return strpos($vbrpNoComments, 'id="' . $id . '"') === false;
    })) === 0
);
check(
    'the finished button on vbrp.php opens a dropdown listing all uploaded files in finished',
    strpos($vbrpNoComments, 'data-vbrp-file="a.xlsx"') !== false
        && strpos($vbrpNoComments, 'data-vbrp-file="b.xlsx"') !== false
        && strpos($vbrpNoComments, 'data-vbrp-open-folder="1"') !== false
);
check(
    'vbrp.php shows the files and rows of the Job in its status bar and the count waiting in upload here',
    strpos(plainText($vbrpPage), 'ไฟล์: 2 | รายการ: 8') !== false && strpos($vbrpPage, 'id="vbrp-upload-count" class="tabular-num text-dark">2<') !== false
);
check(
    'vbrp.php loads the column colour script last and leads back to the portal of its Job',
    preg_match('/<script src="assets\/js\/workspace\/column-highlight\.js[^>]*><\/script>\s*<\/body>/', $vbrpPage) === 1
        && strpos($vbrpPage, 'href="job.php?job_id=' . $jobId . '"') !== false
);

$portal = page('job.php', ['job_id' => $jobId]);
check(
    'the portal of the Job has no VBRP card any more (the user no longer uses vbrp.php), while vbrp.php still opens at its address',
    strpos($portal, 'id="portal-vbrp"') === false && strpos($portal, 'vbrp.php') === false && strpos($portal, 'id="portal-tax-invoice"') !== false
        && strpos($vbrpPage, 'id="vbrp-table"') !== false,
    plainText($portal)
);

$freshJob = $jm->createJob('Fresh vbrp');
$gone = $jm->createJob('Gone vbrp');
$goneFolder = $jm->getJobFolderDir($gone, false);
rename($goneFolder, $goneFolder . '_moved');
$unknown = [];
foreach (['no id' => [], 'unknown id' => ['job_id' => 999999], 'folder gone' => ['job_id' => $gone['id']]] as $label => $get) {
    if (page('vbrp.php', $get) !== page('workspace.php', $get)) {
        $unknown[] = $label;
    }
}
rename($goneFolder . '_moved', $goneFolder);
check('an unknown Job, no id and a Job whose folder is gone get the same answer from vbrp.php as from workspace.php', $unknown === [], json_encode($unknown));

$freshPage = page('vbrp.php', ['job_id' => $freshJob['id']]);
check(
    'a Job with nothing read shows the blank grid rows and zero counts',
    substr_count($freshPage, 'placeholder-grid-row') === 18 && strpos(plainText($freshPage), 'ไฟล์: 0 | รายการ: 0') !== false
);

// --- clear and folders
$cleared = api('job_vbrp_clear', ['job_id' => $jobId]);
check(
    'clearing forgets the files and rows of the Job and keeps the Excel files in finished',
    ($cleared['deleted_files'] ?? null) === 2 && ($cleared['deleted_rows'] ?? null) === 8 && $jm->vbrpCounts($jobId) === ['files' => 0, 'rows' => 0, 'invoices' => 0] && is_file($finished . '/a.xlsx')
);

$jm->clearJobData($jobId);
check('clearing the Tax Invoice data of the Job keeps the VBRP folder while it holds files', is_file($finished . '/a.xlsx') && is_file($upload . '/empty.xlsx'));

$jm->deleteJob($jobId);
$pdo = App\Database::getConnection();
check(
    'deleting the Job deletes its VBRP folder and rows',
    !is_dir($jm->getJobFolderDir($job, false)) && (int)$pdo->query('SELECT COUNT(*) FROM job_vbrp_files WHERE job_id = ' . $jobId)->fetchColumn() === 0
);

foreach (glob($workDir . '/*') as $file) {
    unlink($file);
}
rmdir($workDir);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
