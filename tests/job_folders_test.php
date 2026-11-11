<?php
// Run: php tests/job_folders_test.php
// The Job folder layout (TAX INVOICE and CREDIT NOTE) and the move of Jobs made before it. Needs no sample PDF.
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

if ($mode === '--paths') {
    require __DIR__ . '/../vendor/autoload.php';
    $startAt = (float)($argv[3] ?? 0);
    while (microtime(true) < $startAt) {
        usleep(500);
    }
    $jm = new App\JobManager();
    $jobId = (int)$argv[2];
    echo json_encode([
        'ti' => $jm->getJobTaxInvoiceDir($jobId),
        'upload' => $jm->getJobUploadDir($jobId),
        'finished' => $jm->getJobFinishedDir($jobId),
        'print' => $jm->getJobPrintDir($jobId),
        'cn' => $jm->getJobCreditNoteDir($jobId),
        'cn_upload' => $jm->getJobCnUploadDir($jobId),
        'cn_finished' => $jm->getJobCnFinishedDir($jobId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode !== '--run') {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_folders_test_' . bin2hex(random_bytes(4));
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
$failures = 0;
$windows = DIRECTORY_SEPARATOR === '\\';
$local = ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'];
$noExplorer = '-d disable_functions=proc_open';

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
    $requestFile = tempnam(sys_get_temp_dir(), 'folders_req_');
    file_put_contents($requestFile, json_encode(['action' => $action, 'post' => $post, 'get' => $get, 'server' => $server]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $phpOptions . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($requestFile));
    unlink($requestFile);
    return json_decode($out, true) ?: ['unparsed' => $out];
}

/**
 * One request that asks for every folder of the Job, as a separate PHP process
 */
function pathsRequest(int $jobId): array
{
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --paths ' . $jobId);
    return json_decode($out, true) ?: ['unparsed' => $out];
}

/**
 * A Job row made without asking for any folder, so its folder is whatever the test puts on disk
 */
function bareJob(string $name): array
{
    global $pdo, $root;
    $pdo->prepare("INSERT INTO jobs (name, status, created_at, updated_at) VALUES (?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$name]);
    $id = (int)$pdo->lastInsertId();
    $dir = $root . DIRECTORY_SEPARATOR . 'myfile' . DIRECTORY_SEPARATOR . $name;
    mkdir($dir, 0777, true);
    return ['id' => $id, 'dir' => $dir];
}

/**
 * @param array $files path inside $dir => bytes
 */
function put(string $dir, array $files): void
{
    foreach ($files as $rel => $bytes) {
        $path = $dir . '/' . $rel;
        if (substr($rel, -1) === '/') {
            @mkdir($path, 0777, true);
            continue;
        }
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    }
}

/**
 * Every file under $dir as relative path => sha256, and every folder as relative path/ => ''
 */
function tree(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
        $out[$item->isDir() ? $rel . '/' : $rel] = $item->isDir() ? '' : hash_file('sha256', $item->getPathname());
    }
    ksort($out);
    return $out;
}

/**
 * tree() of a Job folder in myfile/ joined with tree() of its upload/ folder, whose paths start with "upload:"
 */
function jobTree(string $jobDir): array
{
    $out = tree($jobDir);
    foreach (tree(App\Config::getUploadPath() . DIRECTORY_SEPARATOR . basename($jobDir)) as $rel => $hash) {
        $out['upload:' . $rel] = $hash;
    }
    ksort($out);
    return $out;
}

/**
 * Where a file of the old layout belongs in the new one (waiting files go to upload/, written "upload:")
 */
function newPlace(string $rel): string
{
    $places = [
        'upload here/' => 'upload:invoice/pdf/', 'TAX INVOICE/upload here/' => 'upload:invoice/pdf/',
        'credit note/upload here/' => 'upload:cn/pdf/', 'CREDIT NOTE/upload here/' => 'upload:cn/pdf/', 'VBRP/upload here/' => 'upload:invoice/vbrp/',
        'finished/' => 'TAX INVOICE/finished/', 'print/' => 'TAX INVOICE/print/', 'credit note/' => 'CREDIT NOTE/',
    ];
    foreach ($places as $old => $new) {
        if (strpos($rel, $old) === 0) {
            return $new . substr($rel, strlen($old));
        }
    }
    return $rel;
}

function filesOnly(array $tree): array
{
    return array_filter($tree, function ($hash) {
        return $hash !== '';
    });
}

function names(string $dir): array
{
    $names = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    sort($names);
    return $names;
}

function legacyFiles(): array
{
    return [
        'upload here/waiting.pdf' => random_bytes(3000),
        'upload here/batch/sub/nested.pdf' => random_bytes(2000),
        'upload here/empty sub/' => '',
        'finished/RM Document-0093247359 3.pdf' => random_bytes(5000),
        'finished/93247359-1.pdf' => random_bytes(1000),
        'print/print ครั้งที่ 1/10103/1_93247359-1.pdf' => random_bytes(1000),
        'print/print ครั้งที่ 1/ไม่ระบุสาขา/2_93248051-2.pdf' => random_bytes(1000),
        'print/print ครั้งที่ 2/10114/1_93250402-1.pdf' => random_bytes(1000),
        'print/.tmp_0123456789ab/plan.json' => '[]',
        'credit note/upload here/sub/cn waiting.pdf' => random_bytes(1500),
        'credit note/finished/cn read.pdf' => random_bytes(1500),
    ];
}

$job = $jm->createJob('New layout');
$jobDir = $jm->getJobFolderDir($job, false);
$ds = DIRECTORY_SEPARATOR;
check(
    'a new Job folder in myfile/ holds exactly TAX INVOICE (finished, print) and CREDIT NOTE (finished), no upload here',
    names($jobDir) === ['CREDIT NOTE', 'TAX INVOICE'] && names($jobDir . '/TAX INVOICE') === ['finished', 'print']
        && names($jobDir . '/CREDIT NOTE') === ['finished'],
    json_encode([names($jobDir), names($jobDir . '/TAX INVOICE'), names($jobDir . '/CREDIT NOTE')])
);
$workDir = App\Config::getUploadPath() . $ds . 'New layout';
check(
    'the folder getters answer the places of the new layout: the files are read from upload/<Job>/, the program writes to myfile/<Job>/',
    $jm->getJobTaxInvoiceDir($job) === $jobDir . $ds . 'TAX INVOICE' && $jm->getJobUploadDir($job) === $workDir . $ds . 'invoice' . $ds . 'pdf'
        && $jm->getJobAsnDir($job) === $workDir . $ds . 'invoice' . $ds . 'asn' && $jm->getJobVbrpUploadDir($job) === $workDir . $ds . 'invoice' . $ds . 'vbrp'
        && $jm->getJobFinishedDir($job) === $jobDir . $ds . 'TAX INVOICE' . $ds . 'finished' && $jm->getJobPrintDir($job) === $jobDir . $ds . 'TAX INVOICE' . $ds . 'print'
        && $jm->getJobPrintRoundDir($job, 3) === $jobDir . $ds . 'TAX INVOICE' . $ds . 'print' . $ds . 'print ครั้งที่ 3'
        && $jm->getJobCreditNoteDir($job) === $jobDir . $ds . 'CREDIT NOTE' && $jm->getJobCnUploadDir($job) === $workDir . $ds . 'cn' . $ds . 'pdf'
        && $jm->getJobCnAsnDir($job) === $workDir . $ds . 'cn' . $ds . 'asn' && $jm->getJobCnFinishedDir($job) === $jobDir . $ds . 'CREDIT NOTE' . $ds . 'finished'
);

check(
    'a new Job also gets upload/<Job>/ with invoice (pdf, asn, vbrp) and cn (pdf, asn), outside myfile/',
    names($workDir) === ['cn', 'invoice'] && names($workDir . '/invoice') === ['asn', 'pdf', 'vbrp'] && names($workDir . '/cn') === ['asn', 'pdf']
        && $jm->getJobWorkDir($job, false) === $workDir
        && $jm->getJobWorkInvoiceDir($job, false) === $workDir . $ds . 'invoice' && $jm->getJobWorkCnDir($job, false) === $workDir . $ds . 'cn',
    json_encode([names($workDir), names($workDir . '/invoice'), names($workDir . '/cn')])
);

$scan = api('scan_job_files', [], ['job_id' => $job['id']]);
$cnScan = api('job_cn_scan', [], ['job_id' => $job['id']]);
check(
    'the two scans name their folders as the pages show them, upload/<Job>/invoice/pdf, upload/<Job>/cn/pdf and the finished folders of myfile/<Job>/',
    ($scan['data']['upload_dir'] ?? '') === 'upload/New layout/invoice/pdf' && ($scan['data']['finished_dir'] ?? '') === 'myfile/New layout/TAX INVOICE/finished'
        && ($cnScan['data']['upload_dir'] ?? '') === 'upload/New layout/cn/pdf' && ($cnScan['data']['finished_dir'] ?? '') === 'myfile/New layout/CREDIT NOTE/finished',
    json_encode([$scan['data'] ?? $scan, $cnScan['data'] ?? $cnScan], JSON_UNESCAPED_UNICODE)
);

$workOf = function (string $jobDir): string {
    return App\Config::getUploadPath() . DIRECTORY_SEPARATOR . basename($jobDir);
};

$legacy = bareJob('Made before the split');
put($legacy['dir'], legacyFiles());
$before = tree($legacy['dir']);
$answered = pathsRequest($legacy['id']);
$after = jobTree($legacy['dir']);
$expected = [];
foreach (filesOnly($before) as $rel => $hash) {
    $expected[newPlace($rel)] = $hash;
}
ksort($expected);
check(
    'the first request for a Job made before the split moves finished and print into TAX INVOICE, credit note to CREDIT NOTE, and the waiting files of both upload here folders to upload/<Job>/invoice/pdf and cn/pdf, every file byte for byte, print rounds and nested upload folders included',
    filesOnly($after) === $expected && isset($after['upload:invoice/pdf/empty sub/']) && isset($after['TAX INVOICE/print/.tmp_0123456789ab/'])
        && !isset($after['TAX INVOICE/upload here/']) && !isset($after['CREDIT NOTE/upload here/']),
    json_encode(array_keys(array_diff_assoc(filesOnly($after), $expected) + array_diff_assoc($expected, filesOnly($after))), JSON_UNESCAPED_UNICODE)
);
check(
    'afterwards the Job folder holds only TAX INVOICE and CREDIT NOTE, written in capitals on disk',
    names($legacy['dir']) === ['CREDIT NOTE', 'TAX INVOICE'],
    json_encode(names($legacy['dir']), JSON_UNESCAPED_UNICODE)
);
$dir = $legacy['dir'];
check(
    'that request already answers with the new places',
    $answered === ['ti' => "$dir{$ds}TAX INVOICE", 'upload' => $workOf($dir) . "{$ds}invoice{$ds}pdf", 'finished' => "$dir{$ds}TAX INVOICE{$ds}finished",
        'print' => "$dir{$ds}TAX INVOICE{$ds}print", 'cn' => "$dir{$ds}CREDIT NOTE", 'cn_upload' => $workOf($dir) . "{$ds}cn{$ds}pdf",
        'cn_finished' => "$dir{$ds}CREDIT NOTE{$ds}finished"],
    json_encode($answered, JSON_UNESCAPED_UNICODE)
);
$again = pathsRequest($legacy['id']);
check('a second request changes nothing and answers the same', jobTree($legacy['dir']) === $after && $again === $answered);
$summary = api('job_delete_summary', [], ['job_id' => $legacy['id']]);
check(
    'the moved Job counts what it held: PDFs not read yet in upload/<Job>/invoice/pdf and the printed files and rounds',
    ($summary['data']['upload_files'] ?? null) === 2 && ($summary['data']['print_files'] ?? null) === 3 && ($summary['data']['print_rounds'] ?? null) === 2,
    json_encode($summary['data'] ?? $summary, JSON_UNESCAPED_UNICODE)
);

$cnOnly = bareJob('Only credit notes');
put($cnOnly['dir'], ['credit note/upload here/x.pdf' => 'x', 'credit note/finished/' => '']);
pathsRequest($cnOnly['id']);
check(
    'a Job that has only the old credit note folder gets CREDIT NOTE, its waiting file in upload/<Job>/cn/pdf, and TAX INVOICE made on demand',
    names($cnOnly['dir']) === ['CREDIT NOTE', 'TAX INVOICE'] && is_file($workOf($cnOnly['dir']) . '/cn/pdf/x.pdf')
        && names($cnOnly['dir'] . '/TAX INVOICE') === ['finished', 'print'] && names($cnOnly['dir'] . '/CREDIT NOTE') === ['finished'],
    json_encode(jobTree($cnOnly['dir']), JSON_UNESCAPED_UNICODE)
);

$prev = bareJob('Made with upload here');
put($prev['dir'], [
    'TAX INVOICE/upload here/batch/a.pdf' => 'a',
    'TAX INVOICE/upload here/clash.pdf' => 'the waiting copy',
    'TAX INVOICE/finished/' => '',
    'CREDIT NOTE/upload here/c.pdf' => 'c',
    'VBRP/upload here/v.xlsx' => 'v',
    'VBRP/finished/' => '',
]);
put($workOf($prev['dir']), ['invoice/pdf/clash.pdf' => 'already in upload']);
pathsRequest($prev['id']);
$pw = $workOf($prev['dir']);
check(
    'a Job made with the upload here folders gets the whole upload/<Job>/ layout and its waiting files move there (Tax Invoice PDFs to invoice/pdf, credit notes to cn/pdf, VBRP to invoice/vbrp), subfolders kept',
    names($pw . '/invoice') === ['asn', 'pdf', 'vbrp'] && names($pw . '/cn') === ['asn', 'pdf']
        && is_file($pw . '/invoice/pdf/batch/a.pdf') && is_file($pw . '/cn/pdf/c.pdf') && is_file($pw . '/invoice/vbrp/v.xlsx')
        && !is_dir($prev['dir'] . '/CREDIT NOTE/upload here') && !is_dir($prev['dir'] . '/VBRP/upload here'),
    json_encode(jobTree($prev['dir']), JSON_UNESCAPED_UNICODE)
);
check(
    'a waiting file whose name upload/<Job>/ already holds is not overwritten: both copies stay, the old one in its upload here',
    file_get_contents($pw . '/invoice/pdf/clash.pdf') === 'already in upload'
        && file_get_contents($prev['dir'] . '/TAX INVOICE/upload here/clash.pdf') === 'the waiting copy',
    json_encode(jobTree($prev['dir']), JSON_UNESCAPED_UNICODE)
);

$race = bareJob('Two requests at once');
put($race['dir'], legacyFiles());
$raceBefore = filesOnly(tree($race['dir']));
$startAt = microtime(true) + 2;
$procs = [];
for ($i = 0; $i < 8; $i++) {
    $procs[] = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --paths ' . $race['id'] . ' ' . $startAt, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, null, ['bypass_shell' => true]);
}
$answers = [];
foreach ($procs as $i => $proc) {
    $out = stream_get_contents($pipes[$i][1]);
    $err = stream_get_contents($pipes[$i][2]);
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);
    $answers[] = ['code' => proc_close($proc), 'paths' => json_decode($out, true), 'stderr' => $err];
}
$raceExpected = [];
foreach ($raceBefore as $rel => $hash) {
    $raceExpected[newPlace($rel)] = $hash;
}
ksort($raceExpected);
$clean = array_filter($answers, function ($a) {
    return $a['code'] === 0 && is_array($a['paths']) && count($a['paths']) === 7 && $a['stderr'] === '';
});
check(
    'eight requests moving the same Job at the same moment all answer without an error, and the Job ends in the new layout with every file intact',
    count($clean) === 8 && filesOnly(jobTree($race['dir'])) === $raceExpected && names($race['dir']) === ['CREDIT NOTE', 'TAX INVOICE'],
    json_encode([array_column($answers, 'code'), array_column($answers, 'stderr'), names($race['dir'])], JSON_UNESCAPED_UNICODE)
);

$merge = bareJob('Both layouts');
$same = random_bytes(800);
put($merge['dir'], [
    'finished/old.pdf' => 'old',
    'finished/same name.pdf' => 'the old copy',
    'finished/sub/old nested.pdf' => 'old nested',
    'upload here/' => '',
    'TAX INVOICE/finished/new.pdf' => 'new',
    'TAX INVOICE/finished/same name.pdf' => $same,
    'TAX INVOICE/finished/sub/new nested.pdf' => 'new nested',
    'TAX INVOICE/upload here/' => '',
]);
$mergeAnswer = pathsRequest($merge['id']);
$md = $merge['dir'];
check(
    'when both layouts exist the old files join the new folder, a file whose name the new folder holds is neither overwritten nor deleted but stays in the old folder, and an emptied old folder goes',
    filesOnly(tree($md . '/TAX INVOICE/finished')) === [
        'new.pdf' => hash('sha256', 'new'), 'old.pdf' => hash('sha256', 'old'), 'same name.pdf' => hash('sha256', $same),
        'sub/new nested.pdf' => hash('sha256', 'new nested'), 'sub/old nested.pdf' => hash('sha256', 'old nested'),
    ]
        && tree($md . '/finished') === ['same name.pdf' => hash('sha256', 'the old copy')] && !is_dir($md . '/upload here')
        && ($mergeAnswer['finished'] ?? '') === $md . $ds . 'TAX INVOICE' . $ds . 'finished',
    json_encode([tree($md), $mergeAnswer], JSON_UNESCAPED_UNICODE)
);

if (!$windows) {
    echo "SKIP  a folder Windows refuses to move (the check holds a file open, which blocks a rename on Windows only)\n";
} else {
    $locked = bareJob('Locked print folder');
    put($locked['dir'], legacyFiles());
    $lockedBefore = filesOnly(tree($locked['dir']));
    $handle = fopen($locked['dir'] . '/print/print ครั้งที่ 1/10103/1_93247359-1.pdf', 'r');
    $whileLocked = pathsRequest($locked['id']);
    $lockedSummary = api('job_delete_summary', [], ['job_id' => $locked['id']]);
    $ld = $locked['dir'];
    check(
        'a print folder that cannot be moved (a file inside is open) stays where it is without an error, and the getters answer it there while the rest moves',
        ($whileLocked['print'] ?? '') === $ld . $ds . 'print' && ($whileLocked['finished'] ?? '') === $ld . $ds . 'TAX INVOICE' . $ds . 'finished'
            && ($whileLocked['cn_upload'] ?? '') === $workOf($ld) . $ds . 'cn' . $ds . 'pdf'
            && names($ld) === ['CREDIT NOTE', 'TAX INVOICE', 'print'] && !is_dir($ld . '/TAX INVOICE/print'),
        json_encode([$whileLocked, names($ld)], JSON_UNESCAPED_UNICODE)
    );
    check(
        'while it stays there the app still reads the printed files and rounds from it',
        ($lockedSummary['status'] ?? '') === 'success' && ($lockedSummary['data']['print_files'] ?? null) === 3 && ($lockedSummary['data']['print_rounds'] ?? null) === 2,
        json_encode($lockedSummary, JSON_UNESCAPED_UNICODE)
    );
    fclose($handle);
    $lockedExpected = [];
    foreach ($lockedBefore as $rel => $hash) {
        $lockedExpected[newPlace($rel)] = $hash;
    }
    ksort($lockedExpected);
    $released = pathsRequest($locked['id']);
    check(
        'once the file is closed the next request moves the print folder too, every file intact',
        names($ld) === ['CREDIT NOTE', 'TAX INVOICE'] && filesOnly(jobTree($ld)) === $lockedExpected && ($released['print'] ?? '') === $ld . $ds . 'TAX INVOICE' . $ds . 'print',
        json_encode([names($ld), $released], JSON_UNESCAPED_UNICODE)
    );

    $cnLocked = bareJob('Locked credit note folder');
    put($cnLocked['dir'], ['credit note/upload here/a.pdf' => 'a', 'credit note/finished/b.pdf' => 'b', 'finished/' => '']);
    $cnHandle = fopen($cnLocked['dir'] . '/credit note/finished/b.pdf', 'r');
    $cnWhile = pathsRequest($cnLocked['id']);
    $cnScanWhile = api('job_cn_scan', [], ['job_id' => $cnLocked['id']]);
    $cd = $cnLocked['dir'];
    check(
        'a credit note folder that cannot be renamed keeps its name and the credit note side writes to it under that name, while its waiting file still moves to upload/<Job>/cn/pdf and is read there',
        ($cnWhile['cn_upload'] ?? '') === $workOf($cd) . $ds . 'cn' . $ds . 'pdf' && ($cnWhile['cn_finished'] ?? '') === $cd . $ds . 'credit note' . $ds . 'finished'
            && in_array('credit note', names($cd), true) && !is_dir($cd . '/credit note/upload here')
            && array_column($cnScanWhile['data']['new_files'] ?? [], 'rel_path') === ['a.pdf'] && ($cnScanWhile['data']['upload_dir'] ?? '') === 'upload/Locked credit note folder/cn/pdf',
        json_encode([$cnWhile, names($cd), $cnScanWhile], JSON_UNESCAPED_UNICODE)
    );
    fclose($cnHandle);
    pathsRequest($cnLocked['id']);
    check('once released it becomes CREDIT NOTE', names($cd) === ['CREDIT NOTE', 'TAX INVOICE'] && is_file($cd . '/CREDIT NOTE/finished/b.pdf'), json_encode(names($cd)));
}

$open = $jm->createJob('Open targets');
mkdir($jm->getJobPrintRoundDir($open, 1));
$targets = [
    'job' => $jm->getJobFolderDir($open, false),
    'ti' => $jm->getJobTaxInvoiceDir($open),
    'upload' => $jm->getJobUploadDir($open),
    'finished' => $jm->getJobFinishedDir($open),
    'print' => $jm->getJobPrintDir($open),
    'round' => $jm->getJobPrintRoundDir($open, 1),
    'cn' => $jm->getJobCreditNoteDir($open),
    'cn_upload' => $jm->getJobCnUploadDir($open),
    'cn_finished' => $jm->getJobCnFinishedDir($open),
    'asn' => $jm->getJobAsnDir($open),
    'cn_asn' => $jm->getJobCnAsnDir($open),
    'vbrp_upload' => $jm->getJobVbrpUploadDir($open),
    'work' => $jm->getJobWorkDir($open),
];
if (!$windows) {
    echo "SKIP  open_folder targets (the folder is opened in Explorer, Windows only)\n";
} else {
    $wrong = [];
    foreach ($targets as $target => $dir) {
        $res = api('open_folder', ['job_id' => $open['id'], 'target' => $target, 'round' => 1], [], $local, $noExplorer);
        if (($res['status'] ?? '') !== 'success' || ($res['path'] ?? '') !== realpath($dir)) {
            $wrong[$target] = $res;
        }
    }
    check(
        'open_folder opens the two system folders (ti, cn), every older target in its new place (job, upload, finished, print, round, cn_upload, cn_finished, vbrp_upload) and the folders of upload/<Job>/ (work, asn, cn_asn)',
        $wrong === [] && basename($targets['ti']) === 'TAX INVOICE' && basename($targets['cn']) === 'CREDIT NOTE',
        json_encode($wrong, JSON_UNESCAPED_UNICODE)
    );
}

$clear = $jm->createJob('Clear keeps credit notes');
put($jm->getJobFinishedDir($clear), ['processed.pdf' => 'p']);
put($jm->getJobCnFinishedDir($clear), ['credit.pdf' => 'c']);
put($jm->getJobCnUploadDir($clear), ['waiting credit.pdf' => 'w']);
$cnBefore = tree($jm->getJobCreditNoteDir($clear, false));
$cleared = api('clear_job', ['job_id' => $clear['id']]);
check(
    'clear_job deletes the processed Tax Invoice PDFs and leaves CREDIT NOTE as it was',
    ($cleared['status'] ?? '') === 'success' && !is_file($jm->getJobFinishedDir($clear, false) . '/processed.pdf')
        && tree($jm->getJobCreditNoteDir($clear, false)) === $cnBefore,
    json_encode([$cleared, tree($jm->getJobFolderDir($clear, false))], JSON_UNESCAPED_UNICODE)
);

$asnJob = $jm->createJob('ASN folders');
put($jm->getJobAsnDir($asnJob), ['ASN april.xlsx' => 'april bytes', 'more/ASN may.xlsx' => 'may bytes', 'notes.txt' => 'n', '~$ASN april.xlsx' => 'lock']);
put($jm->getJobCnAsnDir($asnJob), ['CN ASN.xlsx' => 'cn bytes']);
put($root, ['outside.xlsx' => 'secret']);
$pdo->prepare("INSERT INTO job_asn_records (job_id, source_file, invoice_no, ean_upc) VALUES (?, 'ASN april.xlsx [Sheet1]', '1', '1')")->execute([$asnJob['id']]);
$asnList = api('job_asn_folder', [], ['job_id' => $asnJob['id'], 'side' => 'invoice']);
$cnAsnList = api('job_asn_folder', [], ['job_id' => $asnJob['id'], 'side' => 'cn']);
check(
    'job_asn_folder lists the Excel files of upload/<Job>/invoice/asn (subfolders included, not lock files or other types), marks the one the Job imported a sheet of, and reads cn/asn for side cn',
    ($asnList['data']['folder'] ?? '') === 'upload/ASN folders/invoice/asn'
        && array_map(function ($f) { return [$f['rel_path'], $f['imported']]; }, $asnList['data']['files'] ?? []) === [['ASN april.xlsx', true], ['more/ASN may.xlsx', false]]
        && array_column($cnAsnList['data']['files'] ?? [], 'rel_path') === ['CN ASN.xlsx'] && ($cnAsnList['data']['files'][0]['imported'] ?? null) === false,
    json_encode([$asnList, $cnAsnList], JSON_UNESCAPED_UNICODE)
);
$fileBytes = function (string $side, string $relPath) use ($asnJob): string {
    $requestFile = tempnam(sys_get_temp_dir(), 'folders_req_');
    file_put_contents($requestFile, json_encode(['action' => 'job_asn_folder_file', 'post' => [], 'get' => ['job_id' => $asnJob['id'], 'side' => $side, 'rel_path' => $relPath], 'server' => []]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($requestFile));
    unlink($requestFile);
    return $out;
};
check(
    'job_asn_folder_file answers the bytes of a file of the asn folder, and refuses a path outside it or a file that is not Excel',
    $fileBytes('invoice', 'more/ASN may.xlsx') === 'may bytes' && $fileBytes('cn', 'CN ASN.xlsx') === 'cn bytes'
        && strpos($fileBytes('invoice', '../../../outside.xlsx'), 'secret') === false && strpos($fileBytes('invoice', 'notes.txt'), '"status":"error"') !== false
        && strpos($fileBytes('cn', 'more/ASN may.xlsx'), 'may bytes') === false
);

$gone = $jm->createJob('Deleted with both systems');
put($jm->getJobFinishedDir($gone), ['a.pdf' => 'a']);
put($jm->getJobCnFinishedDir($gone), ['b.pdf' => 'b']);
put($jm->getJobWorkInvoiceDir($gone, false) . '/pdf', ['kept.pdf' => 'k']);
$deleted = api('delete_job', ['job_id' => $gone['id'], 'confirm_name' => 'Deleted with both systems']);
check(
    'deleting a Job removes its whole folder, both systems',
    ($deleted['status'] ?? '') === 'success' && !is_dir($jm->getJobFolderDir($gone, false)),
    json_encode($deleted, JSON_UNESCAPED_UNICODE)
);
$goneWork = App\Config::getUploadPath() . DIRECTORY_SEPARATOR . 'Deleted with both systems';
check(
    'deleting a Job leaves upload/<Job>/ and the files in it as they were',
    is_file($goneWork . '/invoice/pdf/kept.pdf') && names($goneWork . '/invoice') === ['asn', 'pdf', 'vbrp'] && names($goneWork . '/cn') === ['asn', 'pdf'],
    json_encode([names($goneWork . '/invoice'), names($goneWork . '/cn')])
);

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
