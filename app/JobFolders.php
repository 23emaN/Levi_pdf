<?php

namespace App;

/**
 * The folders of a Job in myfile/, the only place that knows their names. A Job folder holds one folder per system:
 * TAX INVOICE (upload here, finished, print), CREDIT NOTE (upload here, finished), VBRP (upload here, finished) and
 * COVER SHEET (the cover sheets made by CoverSheet).
 *
 * A Job made before this layout keeps upload here, finished and print directly in its folder and its credit notes in
 * "credit note". The first request that asks for a folder of the Job moves each of them into place by renaming it.
 * A part that cannot be moved (Windows refuses while a file inside is open) stays where it is, the getters answer
 * with that place, and the next request tries again.
 */
class JobFolders
{
    public const TAX_INVOICE = 'TAX INVOICE';
    public const CREDIT_NOTE = 'CREDIT NOTE';
    public const VBRP = 'VBRP';
    public const COVER = 'COVER SHEET';
    public const PRINT_ROUND_PREFIX = 'print ครั้งที่ ';

    private const UPLOAD_DIR = 'upload here';
    private const FINISHED_DIR = 'finished';
    private const PRINT_DIR = 'print';

    /**
     * Where a part sat before the split, as its name in the Job folder => where it belongs now
     */
    private const LEGACY_PLACES = [
        self::UPLOAD_DIR => self::TAX_INVOICE . '/' . self::UPLOAD_DIR,
        self::FINISHED_DIR => self::TAX_INVOICE . '/' . self::FINISHED_DIR,
        self::PRINT_DIR => self::TAX_INVOICE . '/' . self::PRINT_DIR,
        'credit note' => self::CREDIT_NOTE,
    ];

    /** @var array Job folders whose old parts this request already tried to move, as path => true */
    private static $migrated = [];

    /**
     * Job name made safe as a Windows folder name
     */
    public function sanitizeFolderName(string $name, int $jobId = 0): string
    {
        $clean = trim(preg_replace('/[\\/\\\\:*?"<>|]+/', '_', $name), " ._-\t\r\n");
        if ($clean === '') {
            $clean = $jobId > 0 ? ('job_' . $jobId) : 'Job';
        }
        return $clean;
    }

    public function getJobFolderName(array $job): string
    {
        return $this->sanitizeFolderName($job['name'] ?? '', (int)($job['id'] ?? 0));
    }

    public function getJobFolderDir(array $job, bool $autoCreate = true): string
    {
        $dir = Config::getMyfilePath() . DIRECTORY_SEPARATOR . $this->getJobFolderName($job);
        if ($autoCreate && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public function getJobTaxInvoiceDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::TAX_INVOICE, $autoCreate);
    }

    public function getJobUploadDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::TAX_INVOICE . '/' . self::UPLOAD_DIR, $autoCreate);
    }

    public function getJobFinishedDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::TAX_INVOICE . '/' . self::FINISHED_DIR, $autoCreate);
    }

    public function getJobPrintDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::TAX_INVOICE . '/' . self::PRINT_DIR, $autoCreate);
    }

    /**
     * A print round folder; PrintManager creates it by renaming its finished working folder
     */
    public function getJobPrintRoundDir(array $job, int $round): string
    {
        return $this->getJobPrintDir($job, false) . DIRECTORY_SEPARATOR . self::PRINT_ROUND_PREFIX . $round;
    }

    public function getJobCreditNoteDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::CREDIT_NOTE, $autoCreate);
    }

    public function getJobCnUploadDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::CREDIT_NOTE . '/' . self::UPLOAD_DIR, $autoCreate);
    }

    public function getJobCnFinishedDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::CREDIT_NOTE . '/' . self::FINISHED_DIR, $autoCreate);
    }

    public function getJobVbrpDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::VBRP, $autoCreate);
    }

    public function getJobVbrpUploadDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::VBRP . '/' . self::UPLOAD_DIR, $autoCreate);
    }

    public function getJobVbrpFinishedDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::VBRP . '/' . self::FINISHED_DIR, $autoCreate);
    }

    public function getJobCoverDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::COVER, $autoCreate);
    }

    /**
     * A folder of myfile/ as the pages show it: myfile/{Job folder}/TAX INVOICE/upload here
     */
    public static function displayPath(string $dir): string
    {
        $root = Config::getMyfilePath();
        $inside = strpos($dir, $root) === 0 ? 'myfile' . substr($dir, strlen($root)) : $dir;
        return str_replace('\\', '/', $inside);
    }

    /**
     * @param string $relPath a folder of the layout relative to the Job folder, parts joined with /
     */
    private function place(array $job, string $relPath, bool $autoCreate): string
    {
        $jobDir = $this->getJobFolderDir($job, $autoCreate);
        $this->migrate($jobDir);
        $dir = $jobDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->currentPlace($jobDir, $relPath));
        if ($autoCreate && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * $relPath, or its old place while an old part that could not be moved still stands in for it
     */
    private function currentPlace(string $jobDir, string $relPath): string
    {
        foreach (self::LEGACY_PLACES as $legacy => $new) {
            if ($relPath !== $new && strpos($relPath, $new . '/') !== 0) {
                continue;
            }
            $old = $this->legacyEntry($jobDir, $legacy);
            if ($old !== null && !$this->existsExactly($jobDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $new))) {
                return $old . substr($relPath, strlen($new));
            }
        }
        return $relPath;
    }

    /**
     * Moves every old part of the Job folder into its new place, once per request. Safe to repeat and to run in two
     * requests at once: it reads what is on disk each time, a part already gone is skipped, nothing is overwritten.
     */
    private function migrate(string $jobDir): void
    {
        if (isset(self::$migrated[$jobDir])) {
            return;
        }
        self::$migrated[$jobDir] = true;
        foreach (self::LEGACY_PLACES as $legacy => $new) {
            $old = $this->legacyEntry($jobDir, $legacy);
            if ($old !== null) {
                $this->moveInto($jobDir . DIRECTORY_SEPARATOR . $old, $jobDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $new));
            }
        }
    }

    /**
     * The name of the old folder $legacy in the Job folder, in the case it has on disk. "CREDIT NOTE" is the new
     * folder, not an old "credit note", although Windows treats the two names as one.
     */
    private function legacyEntry(string $jobDir, string $legacy): ?string
    {
        foreach ($this->entries($jobDir) as $name) {
            if (strcasecmp($name, $legacy) === 0 && $name !== self::CREDIT_NOTE && is_dir($jobDir . DIRECTORY_SEPARATOR . $name)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Renames the folder $src to $dst. When $dst exists the entries of $src move into it one by one, and an entry
     * whose name $dst already holds stays in $src, so no file is overwritten. $src goes only once it is empty.
     */
    private function moveInto(string $src, string $dst): void
    {
        if (!$this->existsExactly($dst)) {
            if (!is_dir(dirname($dst))) {
                @mkdir(dirname($dst), 0777, true);
            }
            if (@rename($src, $dst) || !is_dir($src) || !$this->existsExactly($dst)) {
                return;
            }
        }
        foreach ($this->entries($src) as $name) {
            $from = $src . DIRECTORY_SEPARATOR . $name;
            $to = $dst . DIRECTORY_SEPARATOR . $name;
            if (is_dir($from) && is_dir($to)) {
                $this->moveInto($from, $to);
            } elseif (!file_exists($to)) {
                @rename($from, $to);
            }
        }
        @rmdir($src);
    }

    /**
     * True when the folder exists under exactly this name, not only under another case of it
     */
    private function existsExactly(string $path): bool
    {
        return is_dir($path) && in_array(basename($path), $this->entries(dirname($path)), true);
    }

    private function entries(string $dir): array
    {
        $names = @scandir($dir);
        return $names === false ? [] : array_values(array_diff($names, ['.', '..']));
    }

    public function jobFolderExists(array $job): bool
    {
        return is_dir($this->getJobFolderDir($job, false));
    }

    public function deleteDirectoryRecursive(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectoryRecursive($path);
            } else {
                @chmod($path, 0777);
                @unlink($path);
            }
        }
        @chmod($dir, 0777);
        if (!@rmdir($dir)) {
            if (DIRECTORY_SEPARATOR === '\\') {
                @exec('attrib -r -s -h ' . escapeshellarg($dir));
            }
            usleep(15000);
            return @rmdir($dir);
        }
        return true;
    }

    /**
     * Files still waiting in the Tax Invoice "upload here" are the user's unprocessed input, so the folder stays
     * and only the processed "finished" files go. The CREDIT NOTE folder belongs to the credit note system, which
     * has its own clear button, so it keeps the folder too.
     */
    public function removeProcessedJobFolder(array $job): void
    {
        $jobFolder = $this->getJobFolderDir($job, false);
        if (!is_dir($jobFolder)) {
            return;
        }

        if (FileManager::containsFiles($this->getJobUploadDir($job, false)) || FileManager::containsFiles($this->getJobPrintDir($job, false))
            || FileManager::containsFiles($this->getJobCreditNoteDir($job, false)) || FileManager::containsFiles($this->getJobVbrpDir($job, false))) {
            $this->deleteDirectoryRecursive($this->getJobFinishedDir($job, false));
            return;
        }
        $this->deleteDirectoryRecursive($jobFolder);
    }

    public function deleteJobFolders(int $jobId, ?array $job): void
    {
        $legacyJobDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'job_' . $jobId;
        if (is_dir($legacyJobDir)) {
            $this->deleteDirectoryRecursive($legacyJobDir);
        }
        if ($job) {
            $jobFolder = $this->getJobFolderDir($job, false);
            if (is_dir($jobFolder)) {
                $this->deleteDirectoryRecursive($jobFolder);
            }
        }
    }
}
