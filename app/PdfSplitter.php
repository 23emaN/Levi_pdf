<?php

namespace App;

use setasign\Fpdi\Fpdi;
use RuntimeException;
use InvalidArgumentException;

/**
 * High-performance PDF page extraction and splitting service.
 * Preserves vector layout, fonts, and page sizes from source PDF.
 * Uses atomic temporary writes for safety.
 */
class PdfSplitter
{
    /** Invoice template geometry in PDF points, measured from the bottom-left corner */
    private const INVOICE_NUMBER_X = 424.65;
    private const INVOICE_NUMBER_BASELINE = 652.2;
    private const FOOTER_TOP = 215.0;
    private const FOOTER_BOTTOM = 30.0;

    /** @var array Printed invoice number and footer per page, by source path */
    private $printedCache = [];

    /**
     * Extract specified 1-based page numbers from a source PDF and save as a new PDF.
     *
     * @param string $sourcePdfPath Full path to source PDF
     * @param array $pageNumbers Array of 1-based page indices, e.g. [1, 2]
     * @param string $targetPdfPath Destination path for extracted PDF
     * @param array $edits Per source page number, ['invoice' => string|null, 'hideFooter' => bool]
     * @return bool True on success
     * @throws InvalidArgumentException|RuntimeException
     */
    public function extractPages(string $sourcePdfPath, array $pageNumbers, string $targetPdfPath, array $edits = []): bool
    {
        if (!file_exists($sourcePdfPath)) {
            throw new InvalidArgumentException("Source PDF does not exist: {$sourcePdfPath}");
        }

        if (empty($pageNumbers)) {
            throw new InvalidArgumentException("Page numbers list cannot be empty.");
        }

        $targetDir = dirname($targetPdfPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                throw new RuntimeException("Failed to create target directory: {$targetDir}");
            }
        }

        $pdf = new Fpdi('P', 'pt');
        $pageCount = $pdf->setSourceFile($sourcePdfPath);

        foreach ($pageNumbers as $pNo) {
            $pageIndex = (int)$pNo;
            if ($pageIndex < 1 || $pageIndex > $pageCount) {
                throw new RuntimeException("Page number {$pageIndex} is out of bounds (Total pages: {$pageCount})");
            }

            $templateId = $pdf->importPage($pageIndex);
            $size = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
            $this->applyEdits($pdf, (float)$size['height'], $edits[$pageIndex] ?? []);
        }

        $tempPath = $targetDir . DIRECTORY_SEPARATOR . uniqid('split_', true) . '.tmp';
        $pdf->Output($tempPath, 'F');

        if (!file_exists($tempPath) || filesize($tempPath) === 0) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw new RuntimeException("Generated split PDF is empty or missing: {$tempPath}");
        }

        if (file_exists($targetPdfPath)) {
            @chmod($targetPdfPath, 0777);
            @unlink($targetPdfPath);
        }

        if (!@rename($tempPath, $targetPdfPath)) {
            if (!copy($tempPath, $targetPdfPath)) {
                @unlink($tempPath);
                throw new RuntimeException("Failed to move temporary PDF to target: {$targetPdfPath}");
            }
            @unlink($tempPath);
        }

        return file_exists($targetPdfPath) && filesize($targetPdfPath) > 0;
    }

    /**
     * Cover the printed footer and/or the printed invoice number with white and write the corrected number
     */
    private function applyEdits(Fpdi $pdf, float $pageHeight, array $edit): void
    {
        $pdf->SetFillColor(255, 255, 255);

        if (!empty($edit['hideFooter'])) {
            $pdf->Rect(0, $pageHeight - self::FOOTER_TOP, 595.0, self::FOOTER_TOP - self::FOOTER_BOTTOM, 'F');
        }

        if (!empty($edit['invoice'])) {
            $baseline = $pageHeight - self::INVOICE_NUMBER_BASELINE;
            $pdf->Rect(self::INVOICE_NUMBER_X - 1.0, $baseline - 8.5, 110.0, 11.0, 'F');
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Text(self::INVOICE_NUMBER_X, $baseline, (string)$edit['invoice']);
        }
    }

    /**
     * What each page of a PDF prints, as page number => ['invoice' => string|null, 'footer' => bool]
     */
    public function readPrintedPages(string $pdfPath): array
    {
        if (!isset($this->printedCache[$pdfPath])) {
            $printed = [];
            foreach ((new \Smalot\PdfParser\Parser())->parseFile($pdfPath)->getPages() as $index => $page) {
                $text = $page->getText();
                $invoice = null;
                if (preg_match('/(?:Tax Invoice(?:\s*No)?|CREDIT NOTE No)\s*:\s*([0-9]{7,12}(?:[\s\r\n]*-[\s\r\n]*[0-9]+)?)/i', $text, $m)) {
                    $invoice = preg_replace('/\s+/', '', $m[1]);
                }
                $printed[$index + 1] = ['invoice' => $invoice, 'footer' => strpos($text, 'TOTAL SHIPPED') !== false];
            }
            $this->printedCache[$pdfPath] = $printed;
        }
        return $this->printedCache[$pdfPath];
    }

    public function pageCount(string $pdfPath): int
    {
        return count($this->readPrintedPages($pdfPath));
    }

    /**
     * Edits that make the given pages read as one invoice: its own number on every page, footer on the last page only
     *
     * @return array source page number => ['invoice' => string|null, 'hideFooter' => bool]
     */
    public function planInvoiceEdits(string $sourcePdfPath, array $pages, string $invoice): array
    {
        $printed = $this->readPrintedPages($sourcePdfPath);
        $terminalPage = max($pages);
        $edits = [];
        foreach ($pages as $pNo) {
            $edits[$pNo] = [
                'invoice' => (($printed[$pNo]['invoice'] ?? null) !== $invoice) ? $invoice : null,
                'hideFooter' => $pNo < $terminalPage && !empty($printed[$pNo]['footer']),
            ];
        }
        return $edits;
    }

    public static function hasEdits(array $edits): bool
    {
        foreach ($edits as $edit) {
            if (!empty($edit['invoice']) || !empty($edit['hideFooter'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Write the given pages of a source PDF as one corrected invoice PDF
     */
    public function extractInvoice(string $sourcePdfPath, array $pages, string $invoice, string $targetPdfPath): bool
    {
        return $this->extractPages($sourcePdfPath, $pages, $targetPdfPath, $this->planInvoiceEdits($sourcePdfPath, $pages, $invoice));
    }
}
