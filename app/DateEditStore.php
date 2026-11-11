<?php

namespace App;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * The fixed folders of the "แก้ไขวันที่ PDF" menu, next to myfile/ so the files never count as files of myfile/ or
 * of a Job:
 *   date_edit/upload here/  the user drops the bills here, sub folders allowed
 *   date_edit/finished/     the same sub folders and file names with the date changed; a run again overwrites
 */
class DateEditStore
{
    private const DIR = 'date_edit';
    public const UPLOAD = 'upload';
    public const FINISHED = 'finished';
    private const FOLDERS = [self::UPLOAD => 'upload here', self::FINISHED => 'finished'];

    /** @var string */
    private $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? Config::getRootPath() . DIRECTORY_SEPARATOR . self::DIR;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * One of the two folders (self::UPLOAD or self::FINISHED), created when missing
     */
    public function dir(string $which): string
    {
        if (!isset(self::FOLDERS[$which])) {
            throw new InvalidArgumentException('ไม่รู้จักโฟลเดอร์ที่ขอ');
        }
        $dir = $this->root . DIRECTORY_SEPARATOR . self::FOLDERS[$which];
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('สร้างโฟลเดอร์ไม่ได้: ' . self::displayPath($which));
        }
        return $dir;
    }

    public function uploadDir(): string
    {
        return $this->dir(self::UPLOAD);
    }

    public function finishedDir(): string
    {
        return $this->dir(self::FINISHED);
    }

    /**
     * The folder as the page shows it, e.g. date_edit/upload here/
     */
    public static function displayPath(string $which): string
    {
        return self::DIR . '/' . (self::FOLDERS[$which] ?? '') . '/';
    }

    /**
     * The PDFs waiting in upload here (sub folders included), relative with /, and how many files (of any kind, the
     * ones a clear deletes) each folder holds
     *
     * @return array ['files' => string[], 'upload_count' => int, 'finished_count' => int]
     */
    public function scan(): array
    {
        return [
            'files' => self::relativePdfs($this->uploadDir()),
            'upload_count' => FileManager::countFiles($this->uploadDir()),
            'finished_count' => FileManager::countFiles($this->finishedDir()),
        ];
    }

    /**
     * @return string[]
     */
    private static function relativePdfs(string $dir): array
    {
        return array_map(function (string $path) use ($dir) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($dir) + 1));
        }, FileManager::getPdfFiles($dir));
    }

    /**
     * Change the date of upload here/{$relativePath} and save it as finished/{$relativePath}
     *
     * @return array PdfDateEditor::rewrite result plus 'path'
     */
    public function process(string $relativePath, DateTimeImmutable $date, ?PdfDateEditor $editor = null): array
    {
        $parts = self::pathParts($relativePath);
        $source = $this->inside($this->uploadDir(), $parts, $relativePath);
        if (!is_file($source)) {
            throw new InvalidArgumentException('ไม่พบไฟล์ใน upload here: ' . $relativePath);
        }
        $target = $this->inside($this->finishedDir(), $parts, $relativePath);
        $result = ($editor ?? new PdfDateEditor())->rewrite($source, $target, $date);
        $result['path'] = implode('/', $parts);
        return $result;
    }

    /**
     * The parts of a path relative to one of the folders, refused unless it is a plain path to a PDF inside it
     *
     * @return string[]
     */
    private static function pathParts(string $relativePath): array
    {
        // A name that is not UTF-8 would also break the JSON answer
        if (!preg_match('//u', $relativePath)) {
            throw new InvalidArgumentException('ชื่อไฟล์หรือโฟลเดอร์ไม่ได้เข้ารหัสเป็น UTF-8');
        }
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath)), function ($p) {
            return $p !== '' && $p !== '.';
        }));
        foreach ($parts as $part) {
            if ($part === '..' || preg_match('/[:*?"<>|\x00-\x1F]/u', $part)) {
                throw new InvalidArgumentException('ชื่อไฟล์หรือโฟลเดอร์ไม่ถูกต้อง: ' . $relativePath);
            }
        }
        if (empty($parts) || strtolower(pathinfo(end($parts), PATHINFO_EXTENSION)) !== 'pdf') {
            throw new InvalidArgumentException('รับเฉพาะไฟล์ PDF: ' . $relativePath);
        }
        return $parts;
    }

    private function inside(string $dir, array $parts, string $relativePath): string
    {
        $path = $dir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        if (!FileManager::isSubpath($path, $dir)) {
            throw new InvalidArgumentException('ตำแหน่งไฟล์ไม่ถูกต้อง: ' . $relativePath);
        }
        return $path;
    }

    /**
     * Empty one folder (files and sub folders) and keep the folder itself
     *
     * @return int files deleted
     */
    public function clear(string $which): int
    {
        $dir = $this->dir($which);
        $real = realpath($dir);
        if ($real === false) {
            throw new RuntimeException('ไม่พบโฟลเดอร์ ' . self::displayPath($which));
        }
        $deleted = 0;
        $failed = 0;
        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($tree as $entry) {
            $path = $entry->getPathname();
            if (!FileManager::isSubpath($path, $real)) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($path);
                continue;
            }
            @chmod($path, 0777);
            if (@unlink($path)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        if ($failed > 0) {
            throw new RuntimeException("ลบไม่ได้ {$failed} ไฟล์ (อาจเปิดค้างอยู่ในโปรแกรมอื่น) ลบไปแล้ว {$deleted} ไฟล์");
        }
        return $deleted;
    }

    /**
     * The date picked on the page (an <input type="date"> value, YYYY-MM-DD)
     */
    public static function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new InvalidArgumentException('วันที่ไม่ถูกต้อง');
        }
        return $date;
    }

    /**
     * The edited PDFs in finished, relative with /
     *
     * @return string[]
     */
    public function finishedFiles(): array
    {
        return self::relativePdfs($this->finishedDir());
    }
}
