<?php

namespace App;

use FPDF;
use RuntimeException;

/**
 * Draws the pages of CoverSheet::pages on the form of the sample sheet: assets/cover/form.jpg is the whole A4 form
 * (logos, header, empty table) and every value is written over it at the place the sample writes it, in points.
 * The font is Sarabun (SIL OFL, assets/cover/font) converted for FPDF with cp874.
 */
class CoverSheetPdf
{
    private const FONT = 'Sarabun';
    private const ROW_SIZE = 6.2;
    private const HEADER_SIZE = 7.5;

    /** Baseline of the first table row and the distance between rows, from the sample */
    private const FIRST_ROW = 344.3;
    private const ROW_STEP = 13.0466;
    private const TOTAL_ROW = 593.8;

    /** Left edge of the sequence number, then the centre of each other column */
    private const SEQ_X = 38.3;
    private const CENTRES = [
        'store_no' => 99.2,
        'store_name' => 194.2,
        'invoice' => 294.8,
        'invoice_qty' => 360.0,
        'credit_note' => 425.2,
        'credit_note_qty' => 490.4,
    ];
    private const NAME_WIDTH = 104.0;

    /** Where the header values start, x and baseline */
    private const HEADER = [
        'contact' => [280.6, 170.5],
        'mobile' => [484.7, 187.5],
        'case_label' => [153.1, 221.9],
        'date' => [492.0, 143.5],
    ];

    /** @var string */
    private $assets;

    public function __construct(?string $assets = null)
    {
        $this->assets = $assets ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'cover';
    }

    public function render(array $pages, array $settings, string $target): void
    {
        $pdf = new FPDF('P', 'pt', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetTitle('Cover sheet', false);
        $fontDir = $this->assets . DIRECTORY_SEPARATOR . 'font';
        $pdf->AddFont(self::FONT, '', 'Sarabun-Regular.json', $fontDir);
        $pdf->AddFont(self::FONT, 'B', 'Sarabun-Bold.json', $fontDir);
        $form = $this->assets . DIRECTORY_SEPARATOR . 'form.jpg';
        if (!is_file($form)) {
            throw new RuntimeException('ไม่พบแบบฟอร์มใบปะหน้า assets/cover/form.jpg');
        }

        foreach ($pages as $page) {
            $pdf->AddPage();
            $pdf->Image($form, 0, 0, $pdf->GetPageWidth(), $pdf->GetPageHeight(), 'JPG');

            $pdf->SetFont(self::FONT, 'B', self::HEADER_SIZE);
            foreach (self::HEADER as $field => [$x, $y]) {
                $value = (string)($settings[$field] ?? '');
                if ($value !== '') {
                    $pdf->Text($x, $y, self::text($value));
                }
            }

            $pdf->SetFont(self::FONT, 'B', self::ROW_SIZE);
            foreach ($page['rows'] as $i => $row) {
                $y = self::FIRST_ROW + $i * self::ROW_STEP;
                $pdf->Text(self::SEQ_X, $y, (string)$row['seq']);
                $this->centred($pdf, 'store_no', $y, $page['store_no']);
                $this->centred($pdf, 'store_name', $y, self::fit($pdf, $page['store_name'], self::NAME_WIDTH));
                $this->centred($pdf, 'invoice', $y, $row['invoice'] ?? CoverSheet::EMPTY_CELL);
                $this->centred($pdf, 'invoice_qty', $y, $row['invoice'] === null ? CoverSheet::EMPTY_CELL : '1');
                $this->centred($pdf, 'credit_note', $y, $row['credit_note'] ?? CoverSheet::EMPTY_CELL);
                $this->centred($pdf, 'credit_note_qty', $y, $row['credit_note'] === null ? CoverSheet::EMPTY_CELL : '1');
            }
            if ($page['totals'] !== null) {
                $this->centred($pdf, 'invoice_qty', self::TOTAL_ROW, (string)$page['totals']['invoices']);
                $this->centred($pdf, 'credit_note_qty', self::TOTAL_ROW, (string)$page['totals']['credit_notes']);
            }
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new RuntimeException('สร้างโฟลเดอร์ใบปะหน้าไม่ได้');
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(6)) . '.pdf';
        if (@file_put_contents($tmp, $pdf->Output('S')) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('บันทึกไฟล์ใบปะหน้าไม่ได้ (ไฟล์อาจเปิดค้างอยู่)');
        }
    }

    private function centred(FPDF $pdf, string $column, float $y, string $value): void
    {
        if ($value === '') {
            return;
        }
        $text = self::text($value);
        $pdf->Text(self::CENTRES[$column] - $pdf->GetStringWidth($text) / 2, $y, $text);
    }

    /** A store name shortened with "..." until it fits its column */
    private static function fit(FPDF $pdf, string $value, float $width): string
    {
        if ($pdf->GetStringWidth(self::text($value)) <= $width) {
            return $value;
        }
        while (mb_strlen($value) > 1 && $pdf->GetStringWidth(self::text($value . '...')) > $width) {
            $value = mb_substr($value, 0, -1);
        }
        return rtrim($value) . '...';
    }

    /** UTF-8 to the cp874 bytes of the font; a character cp874 lacks becomes "?" */
    private static function text(string $value): string
    {
        $converted = @iconv('UTF-8', 'CP874//TRANSLIT', $value);
        return $converted === false ? (string)preg_replace('/[^\x20-\x7E]/', '?', $value) : $converted;
    }
}
