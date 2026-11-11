<?php

namespace App;

/**
 * The folders of a Job, the only place that knows their names.
 *
 * upload/{Job folder}/ holds the files the user gives the Job, read from where they are and never moved or deleted:
 * invoice/pdf (Tax Invoice PDFs), invoice/asn, invoice/vbrp, cn/pdf (credit note PDFs) and cn/asn. Deleting the Job
 * leaves it as it is.
 *
 * myfile/{Job folder}/ holds what the program makes, one folder per system: TAX INVOICE (finished, print),
 * CREDIT NOTE (finished), VBRP (finished) and COVER SHEET (the cover sheets made by CoverSheet). "finished" keeps a
 * copy of each file read, which the fix, split and print steps use.
 *
 * A Job made before upload/ kept its waiting files in an "upload here" folder of each system in myfile/. The first
 * request that asks for a folder of the Job creates upload/{Job folder}/ and moves those files there, without
 * overwriting a file of the same name (that one stays where it was).
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

    /** The folders of a Job in upload/{Job folder}/, for the files of its work */
    public const WORK_INVOICE = 'invoice';
    public const WORK_CN = 'cn';
    public const WORK_PDF = 'pdf';
    public const WORK_ASN = 'asn';
    public const WORK_VBRP = 'vbrp';

    /** The folders inside invoice and cn, as the folder => its folders */
    public const WORK_LAYOUT = [
        self::WORK_INVOICE => [self::WORK_PDF, self::WORK_ASN, self::WORK_VBRP],
        self::WORK_CN => [self::WORK_PDF, self::WORK_ASN],
    ];

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

    /**
     * Where the Tax Invoice PDFs are read from: upload/{Job folder}/invoice/pdf
     */
    public function getJobUploadDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, self::WORK_INVOICE . '/' . self::WORK_PDF, $autoCreate);
    }

    /**
     * Where the Tax Invoice ASN files are read from: upload/{Job folder}/invoice/asn
     */
    public function getJobAsnDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, self::WORK_INVOICE . '/' . self::WORK_ASN, $autoCreate);
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

    /**
     * Where the credit note PDFs are read from: upload/{Job folder}/cn/pdf
     */
    public function getJobCnUploadDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, self::WORK_CN . '/' . self::WORK_PDF, $autoCreate);
    }

    /**
     * Where the credit note ASN files are read from: upload/{Job folder}/cn/asn
     */
    public function getJobCnAsnDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, self::WORK_CN . '/' . self::WORK_ASN, $autoCreate);
    }

    public function getJobCnFinishedDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::CREDIT_NOTE . '/' . self::FINISHED_DIR, $autoCreate);
    }

    public function getJobVbrpDir(array $job, bool $autoCreate = true): string
    {
        return $this->place($job, self::VBRP, $autoCreate);
    }

    /**
     * Where the VBRP Excel files are read from: upload/{Job folder}/invoice/vbrp
     */
    public function getJobVbrpUploadDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, self::WORK_INVOICE . '/' . self::WORK_VBRP, $autoCreate);
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
     * upload/{Job folder}/: same folder name as in myfile/, holds invoice (pdf, asn, vbrp) and cn (pdf, asn).
     * Deleting the Job leaves it, with the files in it, as it is.
     */
    public function getJobWorkDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, '', $autoCreate);
    }

    public function getJobWorkInvoiceDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, self::WORK_INVOICE, $autoCreate);
    }

    public function getJobWorkCnDir(array $job, bool $autoCreate = true): string
    {
        return $this->workPlace($job, self::WORK_CN, $autoCreate);
    }

    /**
     * Creates upload/{Job folder}/ with every folder of WORK_LAYOUT; one already there is left as it is
     */
    public function createJobWorkFolders(array $job): void
    {
        $this->createWorkLayout($this->workDirOf($this->getJobFolderName($job)));
    }

    private function createWorkLayout(string $workDir): void
    {
        foreach (self::WORK_LAYOUT as $side => $parts) {
            foreach ($parts as $part) {
                $dir = $workDir . DIRECTORY_SEPARATOR . $side . DIRECTORY_SEPARATOR . $part;
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
            }
        }
    }

    private function workDirOf(string $folderName): string
    {
        return Config::getUploadPath() . DIRECTORY_SEPARATOR . $folderName;
    }

    /**
     * @param string $sub a folder of upload/{Job folder}/, parts joined with /, or '' for that folder itself
     */
    private function workPlace(array $job, string $sub, bool $autoCreate): string
    {
        $this->migrate($this->getJobFolderDir($job, false));
        $dir = $this->workDirOf($this->getJobFolderName($job));
        if ($sub !== '') {
            $dir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
        }
        if ($autoCreate && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * A folder of myfile/ or upload/ as the pages show it: myfile/{Job folder}/TAX INVOICE/finished,
     * upload/{Job folder}/invoice/pdf
     */
    public static function displayPath(string $dir): string
    {
        $inside = $dir;
        foreach (['myfile' => Config::getMyfilePath(), 'upload' => Config::getUploadPath()] as $name => $root) {
            if ($dir === $root || strpos($dir, $root . DIRECTORY_SEPARATOR) === 0) {
                $inside = $name . substr($dir, strlen($root));
                break;
            }
        }
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
        if (isset(self::$migrated[$jobDir]) || !is_dir($jobDir)) {
            return;
        }
        self::$migrated[$jobDir] = true;
        foreach (self::LEGACY_PLACES as $legacy => $new) {
            $old = $this->legacyEntry($jobDir, $legacy);
            if ($old !== null) {
                $this->moveInto($jobDir . DIRECTORY_SEPARATOR . $old, $jobDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $new));
            }
        }
        $this->moveWaitingFiles($jobDir);
    }

    /**
     * Gives the Job its upload/{Job folder}/ and moves the files still waiting in the "upload here" folders of
     * myfile/ there: Tax Invoice PDFs to invoice/pdf, credit note PDFs to cn/pdf, VBRP files to invoice/vbrp. A file
     * whose name the new folder already holds stays where it was; an emptied "upload here" goes.
     */
    private function moveWaitingFiles(string $jobDir): void
    {
        $workDir = $this->workDirOf(basename($jobDir));
        $this->createWorkLayout($workDir);
        $invoicePdf = self::WORK_INVOICE . '/' . self::WORK_PDF;
        $cnPdf = self::WORK_CN . '/' . self::WORK_PDF;
        $sources = [
            self::TAX_INVOICE . '/' . self::UPLOAD_DIR => $invoicePdf,
            self::CREDIT_NOTE . '/' . self::UPLOAD_DIR => $cnPdf,
            self::VBRP . '/' . self::UPLOAD_DIR => self::WORK_INVOICE . '/' . self::WORK_VBRP,
        ];
        // An old part the move above could not rename (a file inside was open) still holds its own "upload here"
        $oldUpload = $this->legacyEntry($jobDir, self::UPLOAD_DIR);
        if ($oldUpload !== null) {
            $sources[$oldUpload] = $invoicePdf;
        }
        $oldCn = $this->legacyEntry($jobDir, 'credit note');
        if ($oldCn !== null) {
            $sources[$oldCn . '/' . self::UPLOAD_DIR] = $cnPdf;
        }
        foreach ($sources as $from => $to) {
            $src = $jobDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $from);
            if (is_dir($src)) {
                $this->moveInto($src, $workDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $to));
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
     * Printed files and the CREDIT NOTE and VBRP folders (each system has its own clear button) keep the Job folder,
     * and only the processed "finished" files go. upload/{Job folder}/ is never touched.
     */
    public function removeProcessedJobFolder(array $job): void
    {
        $jobFolder = $this->getJobFolderDir($job, false);
        if (!is_dir($jobFolder)) {
            return;
        }

        if (FileManager::containsFiles($this->getJobPrintDir($job, false))
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
