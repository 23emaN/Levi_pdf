<?php
namespace App;

class FileManager
{
    /**
     * ดึงรายการไฟล์ PDF ทั้งหมดในโฟลเดอร์ (ค้นหาแบบ Recursive เข้าไปในโฟลเดอร์ย่อยด้วย)
     */
    public static function getPdfFiles(string $dirPath): array
    {
        if (!is_dir($dirPath)) {
            return [];
        }

        $pdfFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'pdf') {
                $pdfFiles[] = $item->getPathname();
            }
        }

        sort($pdfFiles);
        return $pdfFiles;
    }

    /**
     * Files of a folder (subfolders included) whose extension is one of $extensions, sorted. Excel's lock files
     * (~$name.xlsx, which Excel keeps next to an open workbook) are not workbooks and are left out.
     *
     * @param string[] $extensions lower case, without the dot
     */
    public static function getFilesWithExtension(string $dirPath, array $extensions): array
    {
        if (!is_dir($dirPath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && in_array(strtolower($item->getExtension()), $extensions, true) && strpos($item->getFilename(), '~$') !== 0) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Recursively clean up empty subdirectories inside a directory (never deletes the root dir itself)
     *
     * @param string $dirPath
     * @return void
     */
    /**
     * Copies $srcPath into $dir under its own name, replacing a file of that name. The original stays where it is.
     *
     * @return string the copy, or $srcPath when the copy could not be made (or $srcPath already is that file)
     */
    public static function copyInto(string $srcPath, string $dir): string
    {
        $destPath = $dir . DIRECTORY_SEPARATOR . basename($srcPath);
        if (realpath($srcPath) === realpath($destPath)) {
            return $destPath;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return @copy($srcPath, $destPath) ? $destPath : $srcPath;
    }

    public static function cleanEmptySubdirectories(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            return;
        }

        $realRoot = realpath($dirPath);
        if (!$realRoot) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    $path = $item->getRealPath();
                    if ($path && $path !== $realRoot && self::isSubpath($path, $realRoot)) {
                        self::removeIfDirectoryEmpty($path);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore any iterator errors
        }
    }

    /**
     * Walk up from a file's directory and remove empty parent folders up to stopAtDirPath
     *
     * @param string $filePath
     * @param string $stopAtDirPath
     * @return void
     */
    public static function pruneEmptyParents(string $filePath, string $stopAtDirPath): void
    {
        $realStop = realpath($stopAtDirPath);
        if (!$realStop) {
            return;
        }

        $parent = dirname($filePath);
        while ($parent && is_dir($parent)) {
            $realParent = realpath($parent);
            if (!$realParent || $realParent === $realStop || !self::isSubpath($realParent, $realStop)) {
                break;
            }

            if (!self::removeIfDirectoryEmpty($realParent)) {
                break; // If directory not empty, stop walking up
            }

            $parent = dirname($parent);
        }
    }

    /**
     * Remove directory if empty (or only contains metadata/junk files like Thumbs.db, .DS_Store, desktop.ini)
     *
     * @param string $dir
     * @return bool True if removed, false otherwise
     */
    public static function removeIfDirectoryEmpty(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }

        $remaining = array_diff($items, ['.', '..', 'Thumbs.db', '.DS_Store', 'desktop.ini']);
        if (empty($remaining)) {
            foreach (['Thumbs.db', '.DS_Store', 'desktop.ini'] as $junk) {
                $junkPath = $dir . DIRECTORY_SEPARATOR . $junk;
                if (is_file($junkPath)) {
                    @chmod($junkPath, 0777);
                    @unlink($junkPath);
                }
            }
            @chmod($dir, 0777);
            if (!@rmdir($dir)) {
                // Short wait and retry for Windows file locks or read-only attribute
                if (DIRECTORY_SEPARATOR === '\\') {
                    @exec('attrib -r -s -h ' . escapeshellarg($dir));
                }
                usleep(15000);
                return @rmdir($dir);
            }
            return true;
        }

        return false;
    }

    private const WINDOW_WAIT_SECONDS = 4;
    private const USER_SESSION_START_SECONDS = 4;
    private const POWERSHELL_LIMIT_SECONDS = 15;

    private const NOT_OPENED_REASONS = [
        2 => 'launch_failed',
        3 => 'no_window',
        4 => 'cannot_verify',
        5 => 'not_interactive',
    ];

    /**
     * Opening Explorer only makes sense on the desktop of the computer that runs the server, reached
     * through loopback or through the computer's own LAN address or host name.
     */
    public static function isSameMachine(string $remoteAddr, string $serverAddr): bool
    {
        return in_array($remoteAddr, ['127.0.0.1', '::1'], true) || ($remoteAddr !== '' && $remoteAddr === $serverAddr);
    }

    /**
     * The path travels as base64 so Thai folder names survive the ANSI command line of Windows PHP.
     * A service (AppServ installs Apache as one) has no desktop the user can see, so there the command hands the same
     * script to a temporary task in the session of the logged-on user and reports what that script found.
     */
    public static function explorerCommand(string $path, bool $launch = true): string
    {
        $setPath = '$p = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String(\'' . base64_encode(str_replace('/', '\\', $path)) . '\'));';
        $open = ($launch ? ' try { Invoke-Item -LiteralPath $p -ErrorAction Stop } catch { exit 2 };' : '')
            . ' try { $shell = New-Object -ComObject Shell.Application } catch { exit 4 };'
            . ' $deadline = [DateTime]::Now.AddSeconds(' . self::WINDOW_WAIT_SECONDS . ');'
            . ' do { foreach ($w in @($shell.Windows())) { try { if ($w.Document.Folder.Self.Path -ieq $p) { exit 0 } } catch {} };'
            . ' Start-Sleep -Milliseconds 200 } while ([DateTime]::Now -lt $deadline);'
            . ' exit 3';
        $script = $setPath
            . ' if (-not [Environment]::UserInteractive -or [Diagnostics.Process]::GetCurrentProcess().SessionId -eq 0) { '
            . self::userSessionTask($setPath . $open)
            . ' };'
            . $open;
        return 'powershell -NoProfile -NonInteractive -Command "' . $script . '"';
    }

    /**
     * PowerShell that runs $script as a one-shot scheduled task of the group Users, which Windows starts inside the
     * session of whoever is logged on, then exits with the code of $script. Exits 5 when the task cannot be registered
     * (Apache under an account that may not do this) or never starts (nobody is logged on). The task is always removed.
     * The group is looked up by SID because its name is translated on a Thai Windows. $script must be ASCII.
     */
    private static function userSessionTask(string $script): string
    {
        $encoded = base64_encode(implode("\0", str_split($script)) . "\0");
        return 'try {'
            . ' $service = New-Object -ComObject Schedule.Service; $service.Connect(); $folder = $service.GetFolder(\'\\\');'
            . ' $name = \'PdfReaderOpenFolder-\' + [Guid]::NewGuid().ToString(\'N\');'
            . ' $users = (New-Object Security.Principal.SecurityIdentifier(\'S-1-5-32-545\')).Translate([Security.Principal.NTAccount]).Value;'
            . ' $task = $service.NewTask(0); $task.Principal.GroupId = $users; $task.Principal.RunLevel = 0;'
            . ' $task.Settings.DisallowStartIfOnBatteries = $false; $task.Settings.StopIfGoingOnBatteries = $false;'
            . ' $task.Settings.ExecutionTimeLimit = \'PT1M\';'
            . ' $action = $task.Actions.Create(0); $action.Path = \'powershell.exe\';'
            . ' $action.Arguments = \'-NoProfile -NonInteractive -WindowStyle Hidden -EncodedCommand ' . $encoded . '\';'
            . ' $registered = $folder.RegisterTaskDefinition($name, $task, 6, $users, $null, 4);'
            . ' } catch { exit 5 };'
            . ' $code = 5;'
            . ' try {'
            . ' [void]$registered.Run($null);'
            . ' $until = [DateTime]::Now.AddSeconds(' . (self::WINDOW_WAIT_SECONDS + self::USER_SESSION_START_SECONDS) . ');'
            // 267011 has not run yet, 267009 is running, task states 2 and 4 are queued and running
            . ' do { Start-Sleep -Milliseconds 200; $result = $registered.LastTaskResult; $state = $registered.State;'
            . ' $busy = ($state -eq 2) -or ($state -eq 4) -or ($result -eq 267011) -or ($result -eq 267009)'
            . ' } while ($busy -and [DateTime]::Now -lt $until);'
            . ' if (-not $busy) { if ($result -ge 0 -and $result -le 4) { $code = $result } else { $code = 2 } }'
            . ' } catch {};'
            . ' try { $folder.DeleteTask($name, 0) } catch {};'
            . ' exit $code';
    }

    /**
     * Opens the folder in Explorer and reports whether a window for it really exists on this desktop:
     * ['path' => real path, 'opened' => true] or ['path' => ..., 'opened' => false, 'reason' => code].
     * With $launch false it only checks for a window that already shows the folder.
     */
    public static function openInExplorer(string $path, bool $launch = true): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException('การเปิดโฟลเดอร์ใช้ได้เฉพาะเมื่อโปรแกรมรันบน Windows');
        }
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            throw new \RuntimeException('ไม่พบโฟลเดอร์');
        }
        return ['path' => $realPath] + self::runExplorerCommand(self::explorerCommand($realPath, $launch));
    }

    private static function runExplorerCommand(string $command): array
    {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!function_exists($function)) {
                return ['opened' => false, 'reason' => 'functions_disabled'];
            }
        }
        $discard = ['file', 'NUL', 'w'];
        $process = proc_open($command, [['file', 'NUL', 'r'], $discard, $discard], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['opened' => false, 'reason' => 'powershell_unavailable'];
        }
        $deadline = microtime(true) + self::POWERSHELL_LIMIT_SECONDS;
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(50000);
            $status = proc_get_status($process);
        }
        if ($status['running']) {
            proc_terminate($process);
            proc_close($process);
            return ['opened' => false, 'reason' => 'timeout'];
        }
        proc_close($process);
        if ($status['exitcode'] === 0) {
            return ['opened' => true];
        }
        return ['opened' => false, 'reason' => self::NOT_OPENED_REASONS[$status['exitcode']] ?? 'launch_failed'];
    }

    /**
     * Number of files in a directory tree, leaving out hidden working folders (names starting with a dot)
     */
    public static function countFiles(string $dirPath): int
    {
        if (!is_dir($dirPath)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($dirPath) as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') {
                continue;
            }
            $path = $dirPath . DIRECTORY_SEPARATOR . $name;
            $count += is_dir($path) ? self::countFiles($path) : 1;
        }
        return $count;
    }

    /**
     * True if a directory tree holds any real file (ignoring Thumbs.db, .DS_Store, desktop.ini)
     */
    public static function containsFiles(string $dirPath): bool
    {
        if (!is_dir($dirPath)) {
            return false;
        }

        $junk = ['Thumbs.db', '.DS_Store', 'desktop.ini'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !in_array($item->getFilename(), $junk, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a path is strictly inside a root directory
     */
    public static function isSubpath(string $path, string $root): bool
    {
        $r = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $p = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return (stripos($p, $r) === 0) && ($p !== $r);
    }
}
