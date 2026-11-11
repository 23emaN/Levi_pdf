<?php

namespace App;

use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;

/**
 * Changes the printed document date on every page of a bill PDF (Tax Invoice, Credit Note, Debit Note).
 *
 * The date is found by its position on each page (smalot getDataTm), since the layouts differ:
 *   Tax Invoice  "Date" | ":" | "06-04-2026"   three pieces on one row
 *   Credit Note  "Date: 27.04.2026"             one piece, and page 1 sits lower than the next pages
 *   CN attachment (landscape)  "Credit Note Date: 25.04.2026"
 * Each page is imported with FPDI, the printed date is covered with white and the new date is written at the same
 * place in the same font and size, keeping the separator the bill uses. The old date stays in the content stream
 * under the white box, the same as the invoice number fix of PdfSplitter.
 */
class PdfDateEditor
{
    /** Bills print the date in Helvetica 10 pt; used when the size cannot be read from the page */
    private const DEFAULT_FONT_SIZE = 10.0;

    /** How far apart (pt) the "Date" label and its value may sit vertically and still be one row */
    private const ROW_TOLERANCE = 2.0;

    /** White box around a date in units of the font size: digits rise ~0.72 and have no descender */
    private const BOX_ABOVE = 0.78;
    private const BOX_BELOW = 0.14;
    private const BOX_PAD_X = 0.8;

    private const DATE_ONLY = '/^(\d{2})([-.\/])(\d{2})\2(\d{4})$/';
    /** "Date: 27.04.2026", and "Credit Note Date: 25.04.2026" on the landscape attachment page of a Credit Note */
    private const DATE_INLINE = '/^((?:[A-Za-z][A-Za-z ]*\s)?Date\s*:\s*)((\d{2})([-.\/])(\d{2})\4(\d{4}))\s*$/i';
    private const DATE_LABEL = '/^Date\s*:?$/i';

    /** @var Parser */
    private $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? new Parser();
    }

    /**
     * Where each page prints its document date.
     *
     * @return array 1-based page number => list of ['x', 'y' (baseline from the bottom), 'size', 'prefix' (text
     *               printed before the date in the same piece, '' when the date is a piece of its own), 'date',
     *               'separator']; a page without a date has an empty list
     */
    public function findDates(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            throw new InvalidArgumentException("ไม่พบไฟล์ PDF: {$pdfPath}");
        }
        $found = [];
        foreach ($this->parser->parseFile($pdfPath)->getPages() as $index => $page) {
            $found[$index + 1] = $this->datesOfPage($page);
        }
        return $found;
    }

    private function datesOfPage(Page $page): array
    {
        $pieces = [];
        foreach ($page->getDataTm() as $item) {
            $x = round((float)$item[0][4], 2);
            $y = round((float)$item[0][5], 2);
            $text = trim((string)$item[1]);
            if ($text === '') {
                continue;
            }
            // Some pages draw a block twice at the same place
            $pieces[$x . '|' . $y . '|' . $text] = ['x' => $x, 'y' => $y, 'text' => $text];
        }
        $pieces = array_values($pieces);

        $labels = array_values(array_filter($pieces, function (array $p): bool {
            return (bool)preg_match(self::DATE_LABEL, $p['text']);
        }));

        $raw = null;
        $dates = [];
        foreach ($pieces as $piece) {
            $prefix = null;
            $date = '';
            $separator = '';
            if (preg_match(self::DATE_INLINE, $piece['text'], $m)) {
                $prefix = $m[1];
                $date = $m[2];
                $separator = $m[4];
            } elseif (preg_match(self::DATE_ONLY, $piece['text'], $m) && self::hasLabelBefore($labels, $piece)) {
                $prefix = '';
                $date = $piece['text'];
                $separator = $m[2];
            }
            if ($prefix === null) {
                continue;
            }
            if ($raw === null) {
                $raw = self::rawContent($page);
            }
            $dates[] = [
                'x' => $piece['x'],
                'y' => $piece['y'],
                'size' => self::fontSizeAt($raw, $piece['x'], $piece['y']),
                'prefix' => $prefix,
                'date' => $date,
                'separator' => $separator,
            ];
        }
        return $dates;
    }

    private static function hasLabelBefore(array $labels, array $piece): bool
    {
        foreach ($labels as $label) {
            if (abs($label['y'] - $piece['y']) <= self::ROW_TOLERANCE && $label['x'] < $piece['x']) {
                return true;
            }
        }
        return false;
    }

    /**
     * The decoded content stream(s) of a page, '' when they cannot be read
     */
    private static function rawContent(Page $page): string
    {
        try {
            $contents = $page->get('Contents');
            if ($contents instanceof \Smalot\PdfParser\Element\ElementArray) {
                $raw = '';
                foreach ($contents->getContent() as $part) {
                    $raw .= method_exists($part, 'getContent') ? (string)$part->getContent() . "\n" : '';
                }
                return $raw;
            }
            return method_exists($contents, 'getContent') ? (string)$contents->getContent() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The size of the last "Tf" before the text placed at (x, y), or the default size
     */
    private static function fontSizeAt(string $raw, float $x, float $y): float
    {
        if ($raw === '' || !preg_match_all('/(-?[\d.]+)\s+(-?[\d.]+)\s+(?:Td|TD)\b|\s(-?[\d.]+)\s+Tf\b/', $raw, $all, PREG_SET_ORDER)) {
            return self::DEFAULT_FONT_SIZE;
        }
        $size = null;
        foreach ($all as $m) {
            if (isset($m[3]) && $m[3] !== '') {
                $size = (float)$m[3];
                continue;
            }
            if (abs((float)$m[1] - $x) < 0.02 && abs((float)$m[2] - $y) < 0.02) {
                return ($size !== null && $size > 2 && $size < 40) ? $size : self::DEFAULT_FONT_SIZE;
            }
        }
        return self::DEFAULT_FONT_SIZE;
    }

    /**
     * The date as the bill prints it: day, month, year joined by the bill's own separator
     */
    public static function formatDate(DateTimeInterface $date, string $separator): string
    {
        return $date->format('d') . $separator . $date->format('m') . $separator . $date->format('Y');
    }

    /**
     * Write $src to $dst with the printed date of every page changed to $date.
     *
     * @return array ['pages' => int, 'replaced' => int, 'old_dates' => string[] (distinct), 'new_dates' => string[],
     *                'pages_without_date' => int[]]
     */
    public function rewrite(string $src, string $dst, DateTimeInterface $date): array
    {
        $found = $this->findDates($src);

        $targetDir = dirname($dst);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new RuntimeException("สร้างโฟลเดอร์ไม่ได้: {$targetDir}");
        }

        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCompression(true);
        $pageCount = $pdf->setSourceFile($src);

        $replaced = 0;
        $oldDates = [];
        $newDates = [];
        $withoutDate = [];
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $dates = $found[$pageNo] ?? [];
            if (empty($dates)) {
                $withoutDate[] = $pageNo;
                continue;
            }
            foreach ($dates as $at) {
                $newText = self::formatDate($date, $at['separator']);
                $this->overwrite($pdf, (float)$size['height'], $at, $newText);
                $oldDates[$at['date']] = true;
                $newDates[$newText] = true;
                $replaced++;
            }
        }

        $tempPath = $targetDir . DIRECTORY_SEPARATOR . uniqid('date_', true) . '.tmp';
        $pdf->Output($tempPath, 'F');
        if (!is_file($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);
            throw new RuntimeException('สร้างไฟล์ PDF ใหม่ไม่สำเร็จ');
        }
        if (is_file($dst)) {
            @chmod($dst, 0777);
            @unlink($dst);
        }
        if (!@rename($tempPath, $dst)) {
            $copied = @copy($tempPath, $dst);
            @unlink($tempPath);
            if (!$copied) {
                throw new RuntimeException("บันทึกไฟล์ไม่ได้: {$dst}");
            }
        }

        return [
            'pages' => $pageCount,
            'replaced' => $replaced,
            'old_dates' => array_keys($oldDates),
            'new_dates' => array_keys($newDates),
            'pages_without_date' => $withoutDate,
        ];
    }

    /**
     * Cover the date at $at with white and write $newText at the same baseline
     */
    private function overwrite(Fpdi $pdf, float $pageHeight, array $at, string $newText): void
    {
        $fontSize = (float)$at['size'];
        $pdf->SetFont('Helvetica', '', $fontSize);
        $x = (float)$at['x'] + ($at['prefix'] !== '' ? $pdf->GetStringWidth($at['prefix']) : 0.0);
        $baseline = $pageHeight - (float)$at['y'];
        $width = max($pdf->GetStringWidth($at['date']), $pdf->GetStringWidth($newText));

        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(
            $x - self::BOX_PAD_X,
            $baseline - $fontSize * self::BOX_ABOVE,
            $width + 2 * self::BOX_PAD_X,
            $fontSize * (self::BOX_ABOVE + self::BOX_BELOW),
            'F'
        );
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Text($x, $baseline, $newText);
    }
}
