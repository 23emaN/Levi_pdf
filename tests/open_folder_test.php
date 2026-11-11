<?php

require __DIR__ . '/../vendor/autoload.php';

use App\FileManager;

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function explorerWindows(string $path, bool $close = false): int
{
    $script = '$p = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String(\'' . base64_encode($path) . '\')); $n = 0;'
        . ' foreach ($w in @((New-Object -ComObject Shell.Application).Windows())) {'
        . ' try { if ($w.Document.Folder.Self.Path -ieq $p) { $n++; ' . ($close ? '$w.Quit()' : '') . ' } } catch {} }; $n';
    return (int)trim((string)shell_exec('powershell -NoProfile -NonInteractive -Command "' . $script . '"'));
}

function closeExplorerWindows(string $path): int
{
    $closed = explorerWindows($path, true);
    for ($i = 0; $i < 30 && explorerWindows($path) > 0; $i++) {
        usleep(100000);
    }
    return $closed;
}

function removeTree(string $dir): void
{
    for ($attempt = 0; $attempt < 30 && is_dir($dir); $attempt++) {
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($tree as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
        if (is_dir($dir)) {
            usleep(100000);
        }
    }
}

function postOpenFolder(int $port, array $fields): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($fields),
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $body = (string)@file_get_contents("http://127.0.0.1:$port/api.php?action=open_folder", false, $context);
    return json_decode($body, true) ?: ['raw' => $body];
}

check('a loopback IPv4 request comes from this computer', FileManager::isSameMachine('127.0.0.1', ''));
check('a loopback IPv6 request comes from this computer', FileManager::isSameMachine('::1', ''));
check('a request to the server\'s own LAN address from that address comes from this computer', FileManager::isSameMachine('192.168.1.10', '192.168.1.10'));
check('a request from another LAN address is refused', !FileManager::isSameMachine('192.168.1.20', '192.168.1.10'));
check('a request without addresses is refused', !FileManager::isSameMachine('', ''));
check('a request with only a remote address is refused', !FileManager::isSameMachine('192.168.1.20', ''));

$command = FileManager::explorerCommand('C:/jobs/ทดสอบ งาน/print');
preg_match("/FromBase64String\('([A-Za-z0-9+\/=]+)'\)/", $command, $m);
check(
    'the command line is plain ASCII and carries the exact Thai folder path',
    !preg_match('/[^\x20-\x7e]/', $command) && isset($m[1]) && base64_decode($m[1]) === 'C:\jobs\ทดสอบ งาน\print',
    $command
);

if (PHP_OS_FAMILY !== 'Windows') {
    $threw = false;
    try {
        FileManager::openInExplorer(__DIR__);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    check('opening a folder is an error when the server is not Windows', $threw);
    echo $failures ? "\n$failures FAILED\n" : "\nALL PASS (Explorer checks need Windows)\n";
    exit($failures ? 1 : 0);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'open_folder_test_' . bin2hex(random_bytes(4));
$shown = $base . DIRECTORY_SEPARATOR . 'เปิด โฟลเดอร์';
$hidden = $base . DIRECTORY_SEPARATOR . 'ไม่มีหน้าต่าง';
$root = $base . DIRECTORY_SEPARATOR . 'root';
foreach ([$shown, $hidden, $root . DIRECTORY_SEPARATOR . 'data', $root . DIRECTORY_SEPARATOR . 'myfile'] as $dir) {
    mkdir($dir, 0777, true);
}
$shown = realpath($shown);
$hidden = realpath($hidden);
$rootMyfile = realpath($root . DIRECTORY_SEPARATOR . 'myfile');
$server = null;

try {
    $started = microtime(true);
    $result = FileManager::openInExplorer($shown);
    $seconds = round(microtime(true) - $started, 2);
    check(
        'opening a Thai-named folder reports a verified Explorer window',
        $result === ['path' => $shown, 'opened' => true],
        json_encode($result, JSON_UNESCAPED_UNICODE) . " in {$seconds}s"
    );
    check('an Explorer window on this desktop really shows that folder', explorerWindows($shown) === 1);

    $again = FileManager::openInExplorer($shown);
    check(
        'a folder that is already open counts as opened and gets no second window',
        ($again['opened'] ?? null) === true && explorerWindows($shown) === 1,
        json_encode($again, JSON_UNESCAPED_UNICODE)
    );
    check('the test closes the window it opened', closeExplorerWindows($shown) === 1 && explorerWindows($shown) === 0);

    $started = microtime(true);
    $result = FileManager::openInExplorer($hidden, false);
    $seconds = round(microtime(true) - $started, 2);
    check(
        'a folder no window shows is reported as not opened, within the time limit',
        $result === ['path' => $hidden, 'opened' => false, 'reason' => 'no_window'] && $seconds < 10,
        json_encode($result, JSON_UNESCAPED_UNICODE) . " in {$seconds}s"
    );

    $threw = false;
    try {
        FileManager::openInExplorer($base . DIRECTORY_SEPARATOR . 'missing');
    } catch (RuntimeException $e) {
        $threw = true;
    }
    check('a missing folder is an error, not a report', $threw);

    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    putenv('PDF_READER_ROOT=' . $root);
    $discard = ['file', 'NUL', 'w'];
    $server = proc_open(
        escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port -t " . escapeshellarg(dirname(__DIR__)),
        [['file', 'NUL', 'r'], $discard, $discard],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    for ($i = 0; $i < 50 && !($probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)); $i++) {
        usleep(100000);
    }
    if ($probe) {
        fclose($probe);
    }

    $response = postOpenFolder($port, ['target' => 'root']);
    check(
        'the API opens the myfile folder of the server root and confirms the window',
        $response === ['status' => 'success', 'path' => $rootMyfile, 'opened' => true],
        json_encode($response, JSON_UNESCAPED_UNICODE)
    );
    check('the API window is closed again', closeExplorerWindows($rootMyfile) === 1 && explorerWindows($rootMyfile) === 0);

    $response = postOpenFolder($port, ['target' => 'job', 'job_id' => 999]);
    check(
        'the API still answers an error for a folder that does not exist',
        ($response['status'] ?? '') === 'error' && !isset($response['opened']),
        json_encode($response, JSON_UNESCAPED_UNICODE)
    );
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ([$shown, $hidden, $rootMyfile] as $path) {
        closeExplorerWindows($path);
    }
    putenv('PDF_READER_ROOT');
}

check(
    'no Explorer window is left open for the test folders',
    explorerWindows($shown) + explorerWindows($hidden) + explorerWindows($rootMyfile) === 0
);
removeTree($base);
check('the throwaway folders are removed', !is_dir($base));

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
