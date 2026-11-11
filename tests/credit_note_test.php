<?php
// Run: php tests/credit_note_test.php
// The credit note system of a Job (job_cn_* actions, CreditNoteStore) on real credit notes from tests/fixtures/cn.
// Runs against a throwaway root (PDF_READER_ROOT), never touches data/ or myfile/.

$mode = $argv[1] ?? '';

if ($mode === '--api') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = ['action' => $request['action']] + $request['get'];
    $_POST = $request['post'];
    $_SERVER = $request['server'] + $_SERVER;
    require __DIR__ . '/../api.php';
    exit;
}

const STD = '5048_93251044-1_DN.issue.at_20261016_5048-ROBINSON BOWIN 20186.pdf';
const STD_CENTRAL = '5002_93250803-1_DN.issue.at_20261011_5002-CENTRAL PHUKET 10121.pdf';
const TWO = 'RM Document-Customer Billing(PDF)-0093247387 3.pdf';
const VARIANCE = 'RM Document-Customer Billing(PDF)-0093240270.pdf';
const ATTACHMENT = 'RM Document-Customer Billing(PDF)-0093260445.pdf';
const ZERO = 'RM Document-Customer Billing(PDF)-0093260255.pdf';

if ($mode !== '--run') {
    foreach ([STD, STD_CENTRAL, TWO, VARIANCE, ATTACHMENT, ZERO] as $name) {
        if (!is_file(__DIR__ . '/fixtures/cn/' . $name)) {
            fwrite(STDERR, "SKIP: credit note sample not found: tests/fixtures/cn/$name\n");
            exit(2);
        }
    }
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_cn_test_' . bin2hex(random_bytes(4));
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
use App\FileManager;
use App\JobManager;
use App\PdfExtractor;

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

function api(string $action, array $post = [], array $get = [], array $server = [], string $phpOptions = ''): array
{
    $requestFile = tempnam(sys_get_temp_dir(), 'cn_req_');
    file_put_contents($requestFile, json_encode(['action' => $action, 'post' => $post, 'get' => $get, 'server' => $server]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $phpOptions . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($requestFile));
    unlink($requestFile);
    return json_decode($out, true) ?: ['unparsed' => $out];
}

function sample(string $name, string $dir, string $as = ''): void
{
    @mkdir($dir, 0777, true);
    copy(__DIR__ . '/fixtures/cn/' . $name, $dir . '/' . ($as !== '' ? $as : $name));
}

function pdfNames(string $dir): array
{
    $names = array_map(function ($f) use ($dir) {
        return str_replace('\\', '/', substr($f, strlen($dir) + 1));
    }, FileManager::getPdfFiles($dir));
    sort($names);
    return $names;
}

/**
 * The fields of a job_cn_docs document that extractCnDocuments also gives, amounts as floats (JSON gives 0 for 0.0)
 */
function docView(array $doc): array
{
    $doc = array_intersect_key($doc, array_flip(['filename', 'doc_no', 'doc_date', 'customer', 'format_type', 'pages', 'orig_amount', 'corr_amount', 'diff_amount', 'vat_amount', 'amount']));
    foreach (['orig_amount', 'corr_amount', 'diff_amount', 'vat_amount', 'amount'] as $k) {
        if ($doc[$k] !== null) {
            $doc[$k] = (float)$doc[$k];
        }
    }
    return $doc;
}

function cnDocs(int $jobId): array
{
    return api('job_cn_docs', [], ['job_id' => $jobId]);
}

function expectedDoc(string $filename, string $no, string $date, string $customer, string $format, string $pages, ?float $orig, ?float $corr, ?float $diff, ?float $vat, ?float $amount): array
{
    return [
        'filename' => $filename, 'doc_no' => $no, 'doc_date' => $date, 'customer' => $customer, 'format_type' => $format,
        'pages' => $pages, 'orig_amount' => $orig, 'corr_amount' => $corr, 'diff_amount' => $diff, 'vat_amount' => $vat, 'amount' => $amount,
    ];
}

$isThai = function (string $text): bool {
    return preg_match('/[\x{0E00}-\x{0E7F}]/u', $text) === 1;
};

// Values read off the PDFs and checked against pdftotext -layout
$expected = [
    STD => [expectedDoc(STD, '93251044-1', '09.04.2026', '5048-ROBINSON BOWIN 20186', 'Standard CN', '1', null, null, null, 82.53, 1261.44)],
    TWO => [
        expectedDoc(TWO, '93247387-1', '06-04-2026', '5048-ROBINSON BOWIN 20186', 'Variance / Refund', '1', 1973998.01, 1970735.80, 3262.21, 228.35, 3490.56),
        expectedDoc(TWO, '93247387-2', '06-04-2026', '5048-ROBINSON BOWIN 20186', 'Variance / Refund', '2', 575091.56, 573004.09, 2087.47, 146.13, 2233.60),
    ],
    VARIANCE => [expectedDoc(VARIANCE, '93240270', '27.04.2026', '5036-CDS SILOM COMPLEX 10103', 'Variance / Refund', '1-2', 719704472.39, 716829222.81, 2875249.58, 201267.99, 3076517.57)],
    ATTACHMENT => [expectedDoc(ATTACHMENT, '93260445', '25.04.2026', '-', 'Attachment Sheet', '1', 4322180.23, 4316480.80, 5699.43, 398.97, 6098.40)],
    ZERO => [expectedDoc(ZERO, '93260255', '14.04.2026', '5033-CENTRAL RANGSIT 10107', 'Variance / Refund', '1', 3223958.09, 3223958.09, 0.0, 0.0, 0.0)],
];

$job = $jm->createJob('CN flow');
$jobId = (int)$job['id'];
$cnUpload = $jm->getJobCnUploadDir($job, false);
$cnFinished = $jm->getJobCnFinishedDir($job, false);
$jobDir = $jm->getJobFolderDir($job, false);
check(
    'a new Job reads its credit notes from upload/<Job>/cn/pdf and keeps the copies it read in myfile/<Job>/CREDIT NOTE/finished, next to the TAX INVOICE folder',
    is_dir($cnUpload) && is_dir($cnFinished) && $cnUpload === App\Config::getUploadPath() . DIRECTORY_SEPARATOR . 'CN flow' . DIRECTORY_SEPARATOR . 'cn' . DIRECTORY_SEPARATOR . 'pdf'
        && $cnFinished === $jobDir . DIRECTORY_SEPARATOR . 'CREDIT NOTE' . DIRECTORY_SEPARATOR . 'finished' && is_dir($jobDir . '/TAX INVOICE') && !is_dir($jobDir . '/CREDIT NOTE/upload here')
);

sample(STD, $cnUpload);
sample(TWO, $cnUpload . '/sub');
sample(VARIANCE, $cnUpload . '/sub/deeper');
sample(ATTACHMENT, $cnUpload);
sample(ZERO, $cnUpload);
$notCn = new FPDF();
$notCn->AddPage();
$notCn->SetFont('Arial', '', 12);
$notCn->Cell(0, 10, 'Delivery order 12345');
$notCn->Output('F', $cnUpload . '/delivery order.pdf');
file_put_contents($cnUpload . '/broken.pdf', 'this file is not a PDF');
sample(STD, $jm->getJobUploadDir($job), 'tax invoice waiting.pdf');

$scan = api('job_cn_scan', [], ['job_id' => $jobId]);
$data = $scan['data'] ?? [];
$newRel = array_column($data['new_files'] ?? [], 'rel_path');
sort($newRel);
$expectedRel = [ATTACHMENT, 'sub/deeper/' . VARIANCE, 'sub/' . TWO, STD, ZERO, 'broken.pdf', 'delivery order.pdf'];
sort($expectedRel);
check(
    'the credit note scan lists every PDF of its own folder upload/<Job>/cn/pdf as new, subfolders joined with /, and not the PDF waiting for the Tax Invoice side',
    ($scan['status'] ?? '') === 'success' && $newRel === $expectedRel && ($data['new_count'] ?? 0) === 7 && ($data['existing_count'] ?? -1) === 0
        && ($data['duplicate_count'] ?? -1) === 0 && ($data['total_in_upload'] ?? 0) === 7 && ($data['existing_files'] ?? null) === [] && ($data['duplicate_files'] ?? null) === [],
    json_encode($scan, JSON_UNESCAPED_UNICODE)
);
check(
    'the scan names the two credit note folders as upload/<job folder>/cn/pdf and myfile/<job folder>/CREDIT NOTE/finished',
    ($data['upload_dir'] ?? '') === 'upload/CN flow/cn/pdf' && ($data['finished_dir'] ?? '') === 'myfile/CN flow/CREDIT NOTE/finished'
);
$tiScan = api('scan_job_files', [], ['job_id' => $jobId]);
check(
    'the Tax Invoice scan never sees the credit note PDFs',
    ($tiScan['data']['total_in_myfile'] ?? -1) === 1 && array_column($tiScan['data']['new_files'] ?? [], 'rel_path') === ['tax invoice waiting.pdf'],
    json_encode($tiScan['data'] ?? $tiScan, JSON_UNESCAPED_UNICODE)
);
unlink($jm->getJobUploadDir($job) . '/tax invoice waiting.pdf');

$batch = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode([
    STD, ['rel_path' => 'sub/' . TWO, 'filename' => TWO], 'sub/deeper/' . VARIANCE, ATTACHMENT, ZERO, 'delivery order.pdf', 'broken.pdf',
])]);
check(
    'one batch reads every credit note and keeps going past a PDF that holds no credit note and a file that is no PDF, listing each',
    ($batch['status'] ?? '') === 'success' && ($batch['processed_files'] ?? 0) === 5 && ($batch['documents_count'] ?? 0) === 6
        && ($batch['not_credit_note'] ?? null) === ['delivery order.pdf'] && count($batch['failed'] ?? []) === 1
        && ($batch['failed'][0]['filename'] ?? '') === 'broken.pdf' && $isThai($batch['failed'][0]['message'] ?? '')
        && ($batch['skipped_duplicates'] ?? null) === [] && ($batch['replaced_files'] ?? null) === [],
    json_encode(array_diff_key($batch, ['data' => 1]), JSON_UNESCAPED_UNICODE)
);
$readNames = [STD, TWO, VARIANCE, ATTACHMENT, ZERO];
sort($readNames);
$uploadNames = [ATTACHMENT, 'sub/deeper/' . VARIANCE, 'sub/' . TWO, STD, ZERO, 'broken.pdf', 'delivery order.pdf'];
sort($uploadNames);
check(
    'read files are copied to credit note finished; every file stays where it was in upload/<Job>/cn/pdf, subfolders included',
    pdfNames($cnFinished) === $readNames && pdfNames($cnUpload) === $uploadNames
        && hash_file('sha256', $cnFinished . '/' . TWO) === hash_file('sha256', $cnUpload . '/sub/' . TWO),
    json_encode([pdfNames($cnFinished), pdfNames($cnUpload)], JSON_UNESCAPED_UNICODE)
);

$docs = cnDocs($jobId);
$views = array_map('docView', $docs['data'] ?? []);
$expectedViews = array_merge($expected[STD], $expected[TWO], $expected[VARIANCE], $expected[ATTACHMENT], $expected[ZERO]);
check(
    'job_cn_docs lists one row per credit note document in the order read, with the values printed on the PDFs (ROBINSON and CDS customers, the -1 and -2 of one file as two documents)',
    $views === $expectedViews && ($docs['count'] ?? 0) === 6 && ($docs['counts'] ?? null) === ['files' => 5, 'documents' => 6, 'asn' => ['files' => 0, 'rows' => 0]],
    json_encode([$views, $docs['counts'] ?? null], JSON_UNESCAPED_UNICODE)
);
$shapes = [];
foreach ($docs['data'] ?? [] as $doc) {
    $shapes[] = array_keys($doc) === [
        'id', 'filename', 'doc_no', 'doc_date', 'customer', 'format_type', 'pages', 'orig_amount', 'corr_amount', 'diff_amount', 'vat_amount', 'amount',
        'store_no', 'doc_date_iso', 'shipped_units', 'promo_discount', 'total_amount', 'total_discount', 'after_discount', 'excl_vat', 'vat', 'incl_vat',
        'gp_percent', 'read_ok', 'read_note', 'has_items',
    ] && is_int($doc['id']) && is_string($doc['doc_no']) && is_string($doc['pages']) && is_bool($doc['read_ok']) && $doc['has_items'] === true;
}
check(
    'every document carries exactly the contract fields, the reader\'s fields included, the id as a number, the credit note number as a string and read_ok as a boolean',
    $shapes !== [] && !in_array(false, $shapes, true)
);
check('the batch answers with the documents it inserted, the same rows job_cn_docs lists', ($batch['data'] ?? null) === ($docs['data'] ?? []));
$types = $pdo->query("SELECT doc_no, typeof(orig_amount) AS o, typeof(vat_amount) AS v, typeof(amount) AS a FROM job_cn_docs WHERE job_id = {$jobId} AND doc_no IN ('93251044-1', '93260255') ORDER BY doc_no")->fetchAll();
check(
    'an amount the PDF does not print ("-") is stored as NULL and a printed 0.00 as the number 0',
    $types === [['doc_no' => '93251044-1', 'o' => 'null', 'v' => 'real', 'a' => 'real'], ['doc_no' => '93260255', 'o' => 'real', 'v' => 'real', 'a' => 'real']],
    json_encode($types)
);

sample(STD, $cnUpload, 'std copy.pdf');
$sorted = function (array $names): array {
    sort($names);
    return $names;
};
$scan = api('job_cn_scan', [], ['job_id' => $jobId]);
check(
    'a file whose name is already recorded is existing; a file holding a recorded credit note number under another name is a duplicate naming that file and the numbers',
    $sorted(array_column($scan['data']['existing_files'] ?? [], 'filename')) === $readNames
        && ($scan['data']['duplicate_files'] ?? null) === [['filename' => 'std copy.pdf', 'rel_path' => 'std copy.pdf', 'duplicate_of' => STD, 'doc_nos' => ['93251044-1']]]
        && array_column($scan['data']['new_files'] ?? [], 'filename') === ['broken.pdf', 'delivery order.pdf'],
    json_encode($scan['data'] ?? $scan, JSON_UNESCAPED_UNICODE)
);
unlink($cnUpload . '/' . STD);

$skip = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode(['std copy.pdf'])]);
check(
    'a duplicate is skipped and stays in upload/<Job>/cn/pdf when replacing is not asked',
    ($skip['skipped_duplicates'] ?? null) === [['filename' => 'std copy.pdf', 'duplicate_of' => STD]] && ($skip['processed_files'] ?? -1) === 0
        && is_file($cnUpload . '/std copy.pdf') && (cnDocs($jobId)['counts'] ?? null) === ['files' => 5, 'documents' => 6, 'asn' => ['files' => 0, 'rows' => 0]],
    json_encode($skip, JSON_UNESCAPED_UNICODE)
);
$replace = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode(['std copy.pdf']), 'replace_duplicates' => '1']);
$byNo = [];
foreach (cnDocs($jobId)['data'] ?? [] as $doc) {
    $byNo[$doc['doc_no']][] = $doc['filename'];
}
check(
    'with replace_duplicates the new file takes the credit note over: the old file\'s rows go, the new file\'s rows come',
    ($replace['replaced_files'] ?? null) === [STD] && ($replace['processed_files'] ?? 0) === 1 && ($byNo['93251044-1'] ?? null) === ['std copy.pdf']
        && (cnDocs($jobId)['counts'] ?? null) === ['files' => 5, 'documents' => 6, 'asn' => ['files' => 0, 'rows' => 0]],
    json_encode([$replace['replaced_files'] ?? $replace, $byNo], JSON_UNESCAPED_UNICODE)
);

$before = cnDocs($jobId)['data'] ?? [];
$beforeIds = array_column(array_filter($before, function ($d) {
    return $d['filename'] === TWO;
}), 'id');
sample(TWO, $cnUpload);
$again = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode([TWO])]);
$after = cnDocs($jobId);
$twoRows = array_values(array_filter($after['data'] ?? [], function ($d) {
    return $d['filename'] === TWO;
}));
check(
    'reading a recorded file name again replaces its rows instead of adding a second set',
    ($again['processed_files'] ?? 0) === 1 && ($again['documents_count'] ?? 0) === 2 && ($again['replaced_files'] ?? null) === []
        && ($after['counts'] ?? null) === ['files' => 5, 'documents' => 6, 'asn' => ['files' => 0, 'rows' => 0]] && array_column($twoRows, 'doc_no') === ['93247387-1', '93247387-2']
        && min(array_column($twoRows, 'id')) > max($beforeIds),
    json_encode([$again['processed_files'] ?? $again, $after['counts'] ?? null, $twoRows], JSON_UNESCAPED_UNICODE)
);

sample(STD_CENTRAL, $cnUpload, 'p1.pdf');
sample(STD_CENTRAL, $cnUpload, 'p2.pdf');
$inBatch = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode(['p1.pdf', 'p2.pdf'])]);
$p1 = array_values(array_filter(cnDocs($jobId)['data'] ?? [], function ($d) {
    return $d['doc_no'] === '93250803-1';
}));
check(
    'two new files of one batch holding the same credit note: the first is read, the second skipped as its duplicate',
    ($inBatch['processed_files'] ?? 0) === 1 && ($inBatch['skipped_duplicates'] ?? null) === [['filename' => 'p2.pdf', 'duplicate_of' => 'p1.pdf']]
        && array_map('docView', $p1) === [expectedDoc('p1.pdf', '93250803-1', '09.04.2026', '5002-CENTRAL PHUKET 10121', 'Standard CN', '1', null, null, null, 108.44, 1657.60)],
    json_encode([$inBatch, $p1], JSON_UNESCAPED_UNICODE)
);
sample(STD_CENTRAL, $cnUpload, 'p3.pdf');
$inBatch = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode(['p3.pdf', 'p2.pdf']), 'replace_duplicates' => '1']);
$holders = [];
foreach (cnDocs($jobId)['data'] ?? [] as $doc) {
    if ($doc['doc_no'] === '93250803-1') {
        $holders[] = $doc['filename'];
    }
}
check(
    'with replace_duplicates each later file of the batch replaces the earlier holder, so one file ends up with the credit note',
    ($inBatch['replaced_files'] ?? null) === ['p1.pdf', 'p3.pdf'] && ($inBatch['processed_files'] ?? 0) === 1 && $holders === ['p2.pdf'],
    json_encode([$inBatch['replaced_files'] ?? $inBatch, $holders], JSON_UNESCAPED_UNICODE)
);

mkdir($root . '/outside');
sample(STD, $root . '/outside', 'decoy.pdf');
sample(ZERO, $jm->getJobFinishedDir($job), 'tax invoice side.pdf');
$outside = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode(['../../../../outside/decoy.pdf', 'tax invoice side.pdf'])]);
check(
    'a path outside the credit note folders, or a file in the Tax Invoice finished folder, is not read or moved',
    ($outside['processed_files'] ?? -1) === 0 && ($outside['not_credit_note'] ?? null) === [] && ($outside['failed'] ?? null) === []
        && is_file($root . '/outside/decoy.pdf') && is_file($jm->getJobFinishedDir($job) . '/tax invoice side.pdf'),
    json_encode($outside, JSON_UNESCAPED_UNICODE)
);

$tiEntries = [['filename' => 'ti.pdf', 'storedPath' => '', 'items' => [['page_no' => 1, 'tax_invoice' => '9000001-1', 'barcode' => '8850000000001', 'units' => 1, 'unit_price' => '1.00', 'amount' => '1.00']]]];
$jm->recordExtractedBatch($jobId, $tiEntries, true);
$countsBefore = cnDocs($jobId)['counts'] ?? [];
$finishedBefore = pdfNames($cnFinished);
$cleared = api('job_cn_clear', ['job_id' => $jobId]);
$afterClear = cnDocs($jobId);
check(
    'job_cn_clear forgets every credit note file and document of the Job and says how many; the PDFs stay in finished',
    ($cleared['status'] ?? '') === 'success' && ($cleared['deleted_files'] ?? -1) === ($countsBefore['files'] ?? -2)
        && ($cleared['deleted_documents'] ?? -1) === ($countsBefore['documents'] ?? -2) && ($countsBefore['documents'] ?? 0) > 0
        && ($afterClear['count'] ?? -1) === 0 && ($afterClear['counts'] ?? null) === ['files' => 0, 'documents' => 0, 'asn' => ['files' => 0, 'rows' => 0]] && pdfNames($cnFinished) === $finishedBefore,
    json_encode([$cleared, $countsBefore], JSON_UNESCAPED_UNICODE)
);
check('job_cn_clear leaves the Tax Invoice rows alone', $jm->statusCounts($jobId)['pdf_rows'] === 1);

sample(ZERO, $cnUpload);
api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode([ZERO])]);
$tiClear = api('clear_job', ['job_id' => $jobId]);
check(
    'clearing the Tax Invoice data (clear_job) keeps the credit note rows, the credit note PDFs and the Job folder',
    ($tiClear['status'] ?? '') === 'success' && (cnDocs($jobId)['counts'] ?? null) === ['files' => 1, 'documents' => 1, 'asn' => ['files' => 0, 'rows' => 0]]
        && in_array(ZERO, pdfNames($cnFinished), true) && $jm->jobFolderExists($job) && $jm->statusCounts($jobId)['pdf_rows'] === 0,
    json_encode([cnDocs($jobId)['counts'] ?? null, $jm->jobFolderExists($job)])
);

$summary = api('job_delete_summary', [], ['job_id' => $jobId]);
$plain = $jm->createJob('No credit notes');
$plainSummary = api('job_delete_summary', [], ['job_id' => $plain['id']]);
check(
    'the delete summary counts the credit note files and documents of the Job as integers, 0 when it has none',
    ($summary['data']['cn_files'] ?? null) === 1 && ($summary['data']['cn_documents'] ?? null) === 1
        && ($plainSummary['data']['cn_files'] ?? null) === 0 && ($plainSummary['data']['cn_documents'] ?? null) === 0,
    json_encode([$summary['data'] ?? $summary, $plainSummary['data'] ?? $plainSummary], JSON_UNESCAPED_UNICODE)
);

$deleted = api('delete_job', ['job_id' => $jobId, 'confirm_name' => 'CN flow']);
$left = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM job_cn_files WHERE job_id = {$jobId}) + (SELECT COUNT(*) FROM job_cn_docs WHERE job_id = {$jobId})")->fetchColumn();
check(
    'deleting the Job removes its credit note rows and its credit note folder',
    ($deleted['status'] ?? '') === 'success' && $left === 0 && !is_dir($jobDir),
    json_encode([$deleted, $left], JSON_UNESCAPED_UNICODE)
);

$missing = [];
foreach ([['job_cn_scan', [], ['job_id' => 999999]], ['job_cn_docs', [], ['job_id' => 999999]], ['job_cn_extract_batch', ['job_id' => 999999, 'files' => '[]'], []], ['job_cn_clear', ['job_id' => 999999], []]] as [$action, $post, $get]) {
    $res = api($action, $post, $get);
    if (($res['status'] ?? '') !== 'error' || !$isThai($res['message'] ?? '')) {
        $missing[$action] = $res;
    }
}
check('every credit note action answers a Thai error for a Job that does not exist', $missing === [], json_encode($missing, JSON_UNESCAPED_UNICODE));

$old = $jm->createJob('Made before credit notes');
$jm->deleteDirectoryRecursive($jm->getJobCreditNoteDir($old, false));
$local = ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'];
$noExplorer = '-d disable_functions=proc_open';
$openUpload = api('open_folder', ['job_id' => $old['id'], 'target' => 'cn_upload'], [], $local, $noExplorer);
$openFinished = api('open_folder', ['job_id' => $old['id'], 'target' => 'cn_finished'], [], $local, $noExplorer);
$openOther = api('open_folder', ['job_id' => $old['id'], 'target' => 'cn_other'], [], $local, $noExplorer);
check(
    'open_folder cn_upload and cn_finished open the credit note folders of the Job, creating them for a Job made before credit notes existed',
    ($openUpload['status'] ?? '') === 'success' && ($openUpload['path'] ?? '') === realpath($jm->getJobCnUploadDir($old, false))
        && ($openFinished['status'] ?? '') === 'success' && ($openFinished['path'] ?? '') === realpath($jm->getJobCnFinishedDir($old, false))
        && ($openUpload['opened'] ?? null) === false,
    json_encode([$openUpload, $openFinished], JSON_UNESCAPED_UNICODE)
);
check('open_folder refuses a target it does not know', ($openOther['status'] ?? '') === 'error' && $isThai($openOther['message'] ?? ''), json_encode($openOther, JSON_UNESCAPED_UNICODE));

$pdo->exec("INSERT INTO jobs (name, status, created_at, updated_at) VALUES ('Credit notes before the split', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$beforeSplit = (int)$pdo->lastInsertId();
$splitDir = $root . '/myfile/Credit notes before the split';
sample(STD, $splitDir . '/credit note/upload here/sub');
sample(TWO, $splitDir . '/credit note/upload here');
mkdir($splitDir . '/credit note/finished');
$splitScan = api('job_cn_scan', [], ['job_id' => $beforeSplit]);
$splitRel = array_column($splitScan['data']['new_files'] ?? [], 'rel_path');
sort($splitRel);
$splitRead = api('job_cn_extract_batch', ['job_id' => $beforeSplit, 'files' => json_encode($splitScan['data']['new_files'] ?? [])]);
check(
    'credit notes waiting in the old "credit note" folder of a Job made before the split move to upload/<Job>/cn/pdf on the first request and are scanned and read there, into CREDIT NOTE/finished',
    in_array('CREDIT NOTE', scandir($splitDir), true) && !in_array('credit note', scandir($splitDir), true)
        && $splitRel === [TWO, 'sub/' . STD] && ($splitScan['data']['upload_dir'] ?? '') === 'upload/Credit notes before the split/cn/pdf'
        && ($splitRead['documents_count'] ?? 0) === 3 && pdfNames($splitDir . '/CREDIT NOTE/finished') === [STD, TWO]
        && !is_dir($splitDir . '/CREDIT NOTE/upload here') && pdfNames($root . '/upload/Credit notes before the split/cn/pdf') === [TWO, 'sub/' . STD],
    json_encode([$splitScan, $splitRead['documents_count'] ?? $splitRead], JSON_UNESCAPED_UNICODE)
);

sample(STD, $root . '/cn/sub', 'legacy.pdf');
$legacyRel = 'sub' . DIRECTORY_SEPARATOR . 'legacy.pdf';
$list = api('list_files', [], ['folder' => 'cn']);
check(
    'the legacy list_files keeps its answer: status, total, the files relative to the folder, folder',
    $list === ['status' => 'success', 'total' => 1, 'files' => [$legacyRel], 'folder' => 'cn'],
    json_encode($list, JSON_UNESCAPED_UNICODE)
);
$legacy = api('extract_cn_batch', ['folder' => 'cn', 'files' => json_encode([$legacyRel])]);
$direct = json_decode(json_encode((new PdfExtractor())->extractCnDocuments($root . '/cn/' . $legacyRel)), true);
check(
    'the legacy extract_cn_batch keeps its answer: status, count, processed_files and the extractor\'s documents with amounts as printed',
    array_keys($legacy) === ['status', 'count', 'processed_files', 'data'] && $legacy['count'] === 1 && $legacy['processed_files'] === 1
        && $legacy['data'] === $direct && ($direct[0]['amount'] ?? '') === '1,261.44' && ($direct[0]['customer'] ?? '') === '5048-ROBINSON BOWIN 20186',
    json_encode($legacy, JSON_UNESCAPED_UNICODE)
);
$stats = api('get_stats');
check(
    'the legacy get_stats keeps its three counters',
    array_keys($stats['data'] ?? []) === ['myfile_count', 'invoice_count', 'cn_count'] && ($stats['data']['cn_count'] ?? 0) === 1,
    json_encode($stats)
);

$tables = function () use ($pdo): array {
    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('job_cn_files', 'job_cn_docs') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
};
check('a new database starts at schema version 10 with both credit note tables', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 10 && $tables() === ['job_cn_docs', 'job_cn_files']);
$jobsBefore = (int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
foreach (['job_cn_items', 'job_cn_asn_records', 'job_cn_state', 'job_cn_docs', 'job_cn_files'] as $table) {
    $pdo->exec("DROP TABLE {$table}");
}
$pdo->exec('PRAGMA user_version = 7');
Database::migrateSchema($pdo);
check(
    'a version 7 database gains the credit note tables and moves to version 10, its Jobs kept',
    $tables() === ['job_cn_docs', 'job_cn_files'] && (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 10
        && (int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === $jobsBefore
);

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
