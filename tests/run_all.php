<?php

$withExplorer = in_array('--with-explorer', array_slice($argv, 1), true);
$php = getenv('PHP_BIN') ?: PHP_BINARY;
$tests = __DIR__;
$windows = DIRECTORY_SEPARATOR === '\\';

putenv('PHP_BIN=' . $php);
putenv('PYTHONUTF8=1');
putenv('PYTHONIOENCODING=utf-8');
putenv('PYTHONDONTWRITEBYTECODE=1');

/**
 * Runs a command line and returns [exit code, stdout followed by stderr]; exit code -1 when it cannot start
 */
function runCommand(string $command, bool $windows): array
{
    $out = tempnam(sys_get_temp_dir(), 'run_all_');
    $err = tempnam(sys_get_temp_dir(), 'run_all_');
    $process = @proc_open(
        $command,
        [['file', $windows ? 'NUL' : '/dev/null', 'r'], ['file', $out, 'w'], ['file', $err, 'w']],
        $pipes,
        dirname(__DIR__),
        null,
        ['bypass_shell' => true]
    );
    $code = is_resource($process) ? proc_close($process) : -1;
    $text = (string)@file_get_contents($out) . (string)@file_get_contents($err);
    @unlink($out);
    @unlink($err);
    return [$code, $text];
}

function quote(string $arg): string
{
    return escapeshellarg($arg);
}

// What each tool is needed for, and whether it is there
$missing = [];
[$code, $text] = runCommand(quote($php) . ' -r ' . quote('echo PHP_VERSION;'), $windows);
if ($code !== 0) {
    echo "PHP not found: PHP_BIN is set to {$php}, which does not run. Set PHP_BIN to a PHP 7.3 or newer binary.\n";
    exit(2);
}
$phpVersion = trim($text);
[$code] = runCommand('python --version', $windows);
if ($code !== 0) {
    $missing[] = 'python is not on PATH: verify_docs.py and verify_incremental.py cannot run (install Python 3 and add it to PATH)';
} else {
    foreach (['pypdf', 'openpyxl'] as $module) {
        [$code] = runCommand('python -c ' . quote("import {$module}"), $windows);
        if ($code !== 0) {
            $missing[] = "Python package {$module} is missing: verify_docs.py and verify_incremental.py cannot run (pip install -r tests/requirements.txt)";
        }
    }
}
[, $text] = runCommand('pdftotext -v', $windows);
if (!preg_match('/pdftotext version/i', $text)) {
    $missing[] = 'pdftotext (poppler or xpdf) is not on PATH: verify_docs.py and verify_incremental.py cannot run';
}
$pythonReady = empty($missing);
[$code] = runCommand('node --version', $windows);
if ($code !== 0) {
    $missing[] = 'node is not on PATH: job_safety_test.php skips its checks of the Excel files and portal_test.php its checks of the page scripts (install Node.js)';
}

$suites = [
    ['job_safety_test.php', quote($php) . ' ' . quote($tests . '/job_safety_test.php'), null],
    ['job_folders_test.php', quote($php) . ' ' . quote($tests . '/job_folders_test.php'), null],
    ['credit_note_test.php', quote($php) . ' ' . quote($tests . '/credit_note_test.php'), null],
    ['credit_note_reader_test.php', quote($php) . ' ' . quote($tests . '/credit_note_reader_test.php'), null],
    ['credit_note_compare_test.php', quote($php) . ' ' . quote($tests . '/credit_note_compare_test.php'), null],
    ['portal_test.php', quote($php) . ' ' . quote($tests . '/portal_test.php'), null],
    ['vbrp_test.php', quote($php) . ' ' . quote($tests . '/vbrp_test.php'), null],
    ['vbrp_compare_test.php', quote($php) . ' ' . quote($tests . '/vbrp_compare_test.php'), null],
    ['status_split_test.php', quote($php) . ' ' . quote($tests . '/status_split_test.php'), null],
    ['cover_sheet_test.php', quote($php) . ' ' . quote($tests . '/cover_sheet_test.php'), null],
    ['open_folder_test.php', quote($php) . ' ' . quote($tests . '/open_folder_test.php'),
        $withExplorer ? null : 'opens real Explorer windows, run with --with-explorer'],
    ['verify_docs.py', 'python ' . quote($tests . '/verify_docs.py'), $pythonReady ? null : 'NOT RUN'],
    ['verify_incremental.py', 'python ' . quote($tests . '/verify_incremental.py'), $pythonReady ? null : 'NOT RUN'],
    ['verify_cn.py', 'python ' . quote($tests . '/verify_cn.py'), $pythonReady ? null : 'NOT RUN'],
    ['verify_vbrp.py', 'python ' . quote($tests . '/verify_vbrp.py'), $pythonReady ? null : 'NOT RUN'],
];

$rows = [];
$failed = [];
$notRun = false;
foreach ($suites as [$name, $command, $skipReason]) {
    if ($skipReason !== null) {
        $notRun = $notRun || $skipReason === 'NOT RUN';
        $rows[] = [$name, '-', '-', '-', '-', $skipReason === 'NOT RUN' ? 'NOT RUN (a tool is missing, see below)' : "SKIP ({$skipReason})"];
        continue;
    }
    $started = microtime(true);
    [$code, $text] = runCommand($command, $windows);
    $seconds = number_format(microtime(true) - $started, 1);
    $pass = preg_match_all('/^PASS\b/m', $text);
    $fail = preg_match_all('/^FAIL\b/m', $text);
    $skip = preg_match_all('/^SKIP\b/m', $text);
    if ($code === 2 && preg_match('/^SKIP: /m', $text)) {
        $notRun = true;
        $result = 'NOT RUN (' . trim(substr($text, strpos($text, 'SKIP: ') + 6)) . ')';
    } elseif ($code === 0 && $fail === 0) {
        $result = 'PASS';
    } else {
        $result = $code === -1 ? 'CRASH (could not start)' : ($fail > 0 ? 'FAIL' : "CRASH (exit code {$code})");
        $failed[$name] = $text;
    }
    $rows[] = [$name, (string)$pass, (string)$fail, (string)$skip, $seconds, $result];
}

$header = ['Suite', 'PASS', 'FAIL', 'SKIP', 'Seconds', 'Result'];
$widths = [];
foreach (array_merge([$header], $rows) as $row) {
    foreach ($row as $i => $cell) {
        $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
    }
}
foreach (array_merge([$header], $rows) as $row) {
    $cells = [];
    foreach ($row as $i => $cell) {
        $last = $i === count($row) - 1;
        $cells[] = $last ? $cell : ($i === 0 ? str_pad($cell, $widths[$i]) : str_pad($cell, $widths[$i], ' ', STR_PAD_LEFT));
    }
    echo implode('  ', $cells) . "\n";
}
echo "PHP: {$php} ({$phpVersion})\n";

foreach ($missing as $message) {
    echo "Missing: {$message}\n";
}
foreach ($failed as $name => $text) {
    $lines = preg_split('/\r?\n/', rtrim($text));
    echo "\n--- last 15 lines of {$name} ---\n" . implode("\n", array_slice($lines, -15)) . "\n";
}

exit(!empty($failed) ? 1 : ($notRun ? 2 : 0));
