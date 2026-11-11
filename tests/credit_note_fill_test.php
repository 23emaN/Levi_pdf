<?php
// Run: php tests/credit_note_fill_test.php
// "ใส่ยอด PDF ลง ASN" (tab 4 of credit_note.php, CreditNoteAsnFill): the ASN workbook and its picked sheets are kept,
// the PDF totals are read onto the lines by credit note number, the numbers no PDF has are listed with their count.
// Runs against a throwaway root (PDF_READER_ROOT), never touches data/ or myfile/.

$mode = $argv[1] ?? '';

if ($mode !== '--run') {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_cn_fill_' . bin2hex(random_bytes(4));
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

$root = getenv('PDF_READER_ROOT');

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\JobManager;

$jm = new JobManager();
$pdo = Database::getConnection();
$fill = $jm->creditNoteFill();
$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function show($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

function workbook(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'cn_fill_');
    file_put_contents($path, $content);
    return $path;
}

$job = $jm->createJob('Fill Job');
$jobId = (int)$job['id'];

// Two credit note PDFs as tab 1 stores them: one read with its right-hand totals, one read before them (schema 9)
$pdo->prepare("INSERT INTO job_cn_files (job_id, filename, file_path) VALUES (?, 'a.pdf', 'a.pdf')")->execute([$jobId]);
$fileId = (int)$pdo->lastInsertId();
$insertDoc = $pdo->prepare("INSERT INTO job_cn_docs (job_id, file_id, filename, doc_no, format_type, excl_vat, vat) VALUES (?, ?, 'a.pdf', ?, 'Standard CN', ?, ?)");
$insertDoc->execute([$jobId, $fileId, '93251044-1', 1178.91, 82.52]);
$insertDoc->execute([$jobId, $fileId, '93251050-1', null, null]);

$line = function (int $rowNo, string $docNo, string $barcode, array $values) {
    return ['row_no' => $rowNo, 'doc_no' => $docNo, 'barcode' => $barcode, 'values' => $values];
};
$sheets = [
    [
        'name' => 'ZCR done', 'header_row' => 3, 'last_col' => 3,
        'headers' => ['Invoice No', 'EAN/UPC', 'Quantity', 'Total Cost Ex VAT'],
        'rows' => [
            $line(4, '93251044-1', '5401221426009', ['93251044-1', 5401221426009, 2, 1178.91]),
            $line(5, '93251044-1', '5401221426016', ['93251044-1', 5401221426016, 1, 1178.91]),
            $line(6, '93259999-1', '5401221426023', ['93259999-1', 5401221426023, 4, 10]),
            $line(2, 'header-or-above', '1234567', []),
        ],
    ],
    [
        'name' => 'ZKR', 'header_row' => 1, 'last_col' => 1,
        'headers' => ['CN No', 'Barcode'],
        'rows' => [$line(2, '93251050-1', '5401221426030', ['93251050-1', 5401221426030])],
    ],
    ['name' => 'Empty', 'header_row' => 1, 'last_col' => 0, 'headers' => ['x'], 'rows' => []],
];

$upload = workbook('first workbook');
$answer = $fill->import($jobId, $upload, 'ASN CN.xlsx', UPLOAD_ERR_OK, $sheets);
$data = $fill->data($jobId);
$kept = $root . '/myfile/' . $job['name'] . '/CREDIT NOTE/ASN/ASN CN.xlsx';
check(
    'an import keeps the workbook in CREDIT NOTE/ASN and the picked sheets with every column of each line; a sheet with no line and a line above its header are left out',
    $answer['sheets'] === 2 && $answer['rows'] === 4 && $answer['replaced'] === false
        && is_file($kept) && file_get_contents($kept) === 'first workbook'
        && array_column($data['files'][0]['sheets'] ?? [], 'name') === ['ZCR done', 'ZKR']
        && ($data['files'][0]['sheets'][0]['headers'] ?? null) === ['Invoice No', 'EAN/UPC', 'Quantity', 'Total Cost Ex VAT']
        && array_column($data['rows'], 'row_no') === [4, 5, 6, 2]
        && ($data['rows'][0]['values'] ?? null) === ['93251044-1', 5401221426009, 2, 1178.91]
        && array_unique(array_column($data['rows'], 'pdf_status')) === [''],
    show([$answer, $data['files'], $data['rows']])
);
check(
    'before the PDF is read every line and credit note waits, and none is missing',
    $data['summary']['lines'] === ['total' => 4, 'FOUND' => 0, 'MISSING' => 0, 'pending' => 4]
        && $data['summary']['documents'] === ['total' => 3, 'FOUND' => 0, 'MISSING' => 0, 'pending' => 3]
        && $data['summary']['missing'] === [],
    show($data['summary'])
);

$read = $fill->fillFromPdf($jobId);
$byRow = [];
foreach ($read['rows'] as $row) {
    $byRow[$row['row_no']] = $row;
}
check(
    'reading the PDF puts Total Amt Excluding VAT and VAT (7%) of the credit note on each of its lines, with the PDF file',
    $byRow[4]['pdf_status'] === 'FOUND' && $byRow[4]['pdf_cost'] === 1178.91 && $byRow[4]['pdf_vat'] === 82.52 && $byRow[4]['pdf_file'] === 'a.pdf'
        && $byRow[5]['pdf_status'] === 'FOUND' && $byRow[5]['pdf_cost'] === 1178.91 && $read['pdf_documents'] === 1,
    show($read['rows'])
);
check(
    'a number no PDF has, and a PDF read without its totals, are missing: listed once per credit note with its line count, its sheet and why',
    $byRow[6]['pdf_status'] === 'MISSING' && $byRow[6]['pdf_cost'] === null
        && $byRow[2]['pdf_status'] === 'MISSING' && $byRow[2]['pdf_file'] === App\CreditNoteAsnFill::NO_AMOUNT_NOTE
        && $read['summary']['lines'] === ['total' => 4, 'FOUND' => 2, 'MISSING' => 2, 'pending' => 0]
        && $read['summary']['documents'] === ['total' => 3, 'FOUND' => 1, 'MISSING' => 2, 'pending' => 0]
        && $read['summary']['missing'] === [
            ['doc_no' => '93259999-1', 'rows' => 1, 'where' => ['ASN CN.xlsx / ZCR done'], 'note' => 'ไม่พบใน PDF'],
            ['doc_no' => '93251050-1', 'rows' => 1, 'where' => ['ASN CN.xlsx / ZKR'], 'note' => App\CreditNoteAsnFill::NO_AMOUNT_NOTE],
        ],
    show($read['summary'])
);

// The PDF of the missing number arrives; reading again finds it
$insertDoc->execute([$jobId, $fileId, '93259999 1', 10.0, 0.7]);
$again = $fill->fillFromPdf($jobId);
check(
    'reading again reflects a PDF added since, matching the number on letters and digits only',
    $again['summary']['documents']['FOUND'] === 2 && array_column($again['summary']['missing'], 'doc_no') === ['93251050-1'],
    show($again['summary'])
);

$overview = $fill->overview($jobId);
$docsOf = array_column($overview['documents'], null, 'doc_no');
check(
    'the overview has one row per credit note: found, not found or not read yet, the ASN quantity summed and its first Total Cost Ex VAT, the PDF totals and file',
    $overview['total'] === 3 && $overview['complete'] === 2 && $overview['error'] === 1 && $overview['pending'] === 0
        && $docsOf['93251044-1']['status'] === 'MATCH' && $docsOf['93251044-1']['units'] === 3.0 && $docsOf['93251044-1']['amount'] === 1178.91
        && $docsOf['93251044-1']['pdf_cost'] === 1178.91 && $docsOf['93251044-1']['pdf_vat'] === 82.52 && $docsOf['93251044-1']['source_files'] === ['a.pdf']
        && $docsOf['93251044-1']['lines'] === 2
        && $docsOf['93251050-1']['status'] === 'MISMATCH' && $docsOf['93251050-1']['problem'] === App\CreditNoteAsnFill::NO_AMOUNT_NOTE
        && $docsOf['93251050-1']['units'] === null && $docsOf['93251050-1']['pdf_cost'] === null,
    show($overview)
);

$path = $fill->filePath($jobId, (int)$data['files'][0]['id']);
check('the kept workbook is served by its file id, only in its own Job', $path !== null && realpath($path['path']) === realpath($kept)
    && $path['file_name'] === 'ASN CN.xlsx' && $fill->filePath((int)$jm->createJob('Other Job')['id'], (int)$data['files'][0]['id']) === null);

$replace = $fill->import($jobId, workbook('second workbook'), 'ASN CN.xlsx', UPLOAD_ERR_OK, [$sheets[1]]);
$data = $fill->data($jobId);
check(
    'importing the same file name again replaces that file as a whole: its workbook, sheets, lines and figures',
    $replace['replaced'] === true && count($data['files']) === 1 && array_column($data['files'][0]['sheets'], 'name') === ['ZKR']
        && count($data['rows']) === 1 && $data['rows'][0]['pdf_status'] === '' && file_get_contents($kept) === 'second workbook',
    show($data)
);

$fill->import($jobId, workbook('other'), 'Other.xlsx', UPLOAD_ERR_OK, [$sheets[0]]);
$refused = [];
foreach ([['bad.xls', $sheets], ['none.xlsx', [$sheets[2]]]] as [$name, $picked]) {
    try {
        $fill->import($jobId, workbook('x'), $name, UPLOAD_ERR_OK, $picked);
        $refused[] = false;
    } catch (\InvalidArgumentException $e) {
        $refused[] = true;
    }
}
check('a file that is not .xlsx, or no sheet with a line, is refused and keeps nothing', $refused === [true, true]
    && count($fill->data($jobId)['files']) === 2 && !is_file(dirname($kept) . '/none.xlsx'), show($refused));

$counts = $fill->counts($jobId);
$cleared = $fill->clear($jobId);
check(
    'clearing removes every file of the tab, its lines and kept workbooks, and leaves the credit note PDFs',
    $counts === ['files' => 2, 'rows' => 4] && $cleared === ['files' => 2, 'rows' => 4] && $fill->counts($jobId) === ['files' => 0, 'rows' => 0]
        && !is_file($kept) && (int)$pdo->query("SELECT COUNT(*) FROM job_cn_docs WHERE job_id = {$jobId}")->fetchColumn() === 3,
    show([$counts, $cleared])
);

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
