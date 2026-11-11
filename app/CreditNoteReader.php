<?php

namespace App;

use Smalot\PdfParser\Parser;

/**
 * Reads a credit note PDF into its documents: each is what PdfExtractor::extractCnDocuments returns for it, plus the
 * item lines and the right-hand totals block, read from where smalot places each piece of text on the page, and
 * read_ok, which is true only when the document's own arithmetic holds. The file is parsed once.
 */
class CreditNoteReader
{
    /** The labels of the right-hand totals block, each matched against one whole piece of text */
    private const TOTALS = [
        'promo_discount' => '/^Promotion Discount$/',
        'total_amount' => '/^Total Amount$/',
        'total_discount' => '/^Total Discount\s*\(\s*(\d+(?:\.\d+)?)\s*%\s*\)$/',
        'after_discount' => '/^Total Amt After Discount$/',
        'excl_vat' => '/^Total Amt Excluding VAT$/',
        'vat' => '/^VAT\s*\(\s*\d+(?:\.\d+)?\s*%\s*\)$/',
        'incl_vat' => '/^Total Amt Include VAT$/',
    ];
    private const SHIPPED = '/^TOTAL SHIPPED \(UNITS\):?$/';
    private const AMOUNT = '/^-?\d{1,3}(?:,\d{3})*\.\d{2}$/';
    private const COUNT = '/^\d{1,3}(?:,\d{3})*$/';
    private const BARCODE = '/^\d{13}$/';

    // smalot places a product code 0.2 pt above its barcode and a label 0.2 pt above its amount; printed rows are 10 pt apart
    private const ROW_TOLERANCE = 2.5;
    private const VAT_TOLERANCE_CENTS = 3;

    /** @var Parser */
    private $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    public function read(string $filePath): array
    {
        $pages = $this->parser->parseFile($filePath)->getPages();
        $pageTexts = [];
        foreach ($pages as $key => $page) {
            $pageTexts[$key] = $page->getText();
        }

        $documents = [];
        foreach (PdfExtractor::cnDocumentsOfPages(basename($filePath), $pageTexts) as $found) {
            $pagesRows = [];
            foreach ($found['page_keys'] as $key) {
                $pagesRows[] = self::rows($pages[$key]->getDataTm());
            }
            $documents[] = self::document($found['document'], $pagesRows);
        }
        return $documents;
    }

    private static function document(array $legacy, array $pagesRows): array
    {
        $doc = $legacy + [
            'doc_date_iso' => self::isoDate((string)$legacy['doc_date']),
            'store_no' => self::storeNo((string)$legacy['customer']),
            'items' => self::items($pagesRows),
        ] + self::totals($pagesRows);
        $problems = self::problems($doc);
        $doc['read_ok'] = !$problems;
        $doc['read_note'] = implode('; ', $problems);
        return $doc;
    }

    /**
     * The pieces of text of a page (smalot getDataTm) as printed rows from top to bottom, each a list of
     * ['x' => left edge, 'text' => text] from left to right
     */
    private static function rows(array $dataTm): array
    {
        $pieces = [];
        foreach ($dataTm as $item) {
            $x = (float)$item[0][4];
            $y = (float)$item[0][5];
            $last = count($pieces) - 1;
            // smalot gives the parts of one kerned string ("4,667.2", "3") the same position
            if ($last >= 0 && $pieces[$last]['x'] === $x && $pieces[$last]['y'] === $y) {
                $pieces[$last]['text'] .= $item[1];
                continue;
            }
            $pieces[] = ['x' => $x, 'y' => $y, 'text' => (string)$item[1], 'order' => count($pieces)];
        }
        // Some pages draw their totals block twice at the same place
        $unique = [];
        foreach ($pieces as $piece) {
            $unique += [$piece['x'] . '|' . $piece['y'] . '|' . $piece['text'] => $piece];
        }
        $pieces = array_values($unique);
        usort($pieces, function (array $a, array $b): int {
            return [$b['y'], $a['x'], $a['order']] <=> [$a['y'], $b['x'], $b['order']];
        });

        $rows = [];
        $rowY = null;
        foreach ($pieces as $piece) {
            $text = trim(preg_replace('/\s+/', ' ', $piece['text']));
            if ($text === '') {
                continue;
            }
            if ($rowY === null || $rowY - $piece['y'] > self::ROW_TOLERANCE) {
                $rows[] = [];
                $rowY = $piece['y'];
            }
            $rows[count($rows) - 1][] = ['x' => $piece['x'], 'text' => $text];
        }
        foreach ($rows as $i => $row) {
            usort($row, function (array $a, array $b): int {
                return $a['x'] <=> $b['x'];
            });
            $rows[$i] = $row;
        }
        return $rows;
    }

    /**
     * The item lines between the PRODUCT/SIZE header of each page and the totals block. A line starts at its barcode
     * and ends with units, unit price and amount; rows below it in its description column, on the next page too,
     * continue its description.
     */
    private static function items(array $pagesRows): array
    {
        $items = [];
        $descriptionX = null;
        $endOfTable = array_merge([self::SHIPPED], array_values(self::TOTALS));
        foreach ($pagesRows as $rows) {
            $inTable = false;
            foreach ($rows as $row) {
                if (!$inTable) {
                    $inTable = self::find($row, ['/^PRODUCT\/S(?:IZE|Z)\b/']) !== null;
                    continue;
                }
                if (self::find($row, $endOfTable) !== null) {
                    break;
                }
                $at = self::find($row, [self::BARCODE]);
                if ($at !== null) {
                    list($item, $descriptionX) = self::item($row, $at);
                    $items[] = $item;
                } elseif ($items && $descriptionX !== null && self::allAt($row, $descriptionX)) {
                    $last = count($items) - 1;
                    $items[$last]['description'] = trim($items[$last]['description'] . ' ' . self::joined($row));
                }
            }
        }
        return $items;
    }

    /**
     * units, unit_price and amount are null when the row does not end with them; problems() then fails the document
     *
     * @return array [the item line, the left edge of its description or null]
     */
    private static function item(array $row, int $at): array
    {
        $before = array_slice($row, 0, $at);
        $after = array_slice($row, $at + 1);
        $numbers = array_slice($after, -3);
        $units = $unitPrice = $amount = null;
        if (count($numbers) === 3
            && preg_match(self::COUNT, $numbers[0]['text'])
            && preg_match(self::AMOUNT, $numbers[1]['text'])
            && preg_match(self::AMOUNT, $numbers[2]['text'])) {
            $units = (int)self::number($numbers[0]['text']);
            $unitPrice = self::number($numbers[1]['text']);
            $amount = self::number($numbers[2]['text']);
            $after = array_slice($after, 0, -3);
        }
        $item = [
            'barcode' => $row[$at]['text'],
            'product_code' => $before ? self::joined($before) : null,
            'description' => $after ? self::joined($after) : null,
            'units' => $units,
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ];
        return [$item, $after ? $after[0]['x'] : null];
    }

    /**
     * TOTAL SHIPPED (UNITS), the right-hand totals and the percentage of Total Discount (28%): each the piece right
     * after its label on the label's row, null when the label or its value is not printed
     */
    private static function totals(array $pagesRows): array
    {
        $shipped = self::labelled($pagesRows, self::SHIPPED, '/^\d{1,3}(?:,\d{3})*(?:\.0+)?$/');
        $totals = ['shipped_units' => $shipped && $shipped['value'] !== null ? (int)self::number($shipped['value']) : null];
        $gpPercent = null;
        foreach (self::TOTALS as $field => $label) {
            $found = self::labelled($pagesRows, $label, self::AMOUNT);
            $totals[$field] = $found && $found['value'] !== null ? self::number($found['value']) : null;
            if ($field === 'total_discount' && $found) {
                preg_match($label, $found['label'], $m);
                $gpPercent = (float)$m[1];
            }
        }
        $totals['gp_percent'] = $gpPercent;
        return $totals;
    }

    /**
     * Why the document cannot be trusted, [] when its item lines and totals agree with each other
     */
    private static function problems(array $doc): array
    {
        $problems = [];
        if (!$doc['items']) {
            $problems[] = 'no item lines';
        }
        foreach ($doc['items'] as $n => $item) {
            if ($item['units'] === null) {
                $problems[] = 'item ' . ($n + 1) . ' (' . $item['barcode'] . '): units, unit price or amount not read';
            }
        }
        $missing = [];
        foreach (['shipped_units', 'total_amount', 'total_discount', 'after_discount', 'excl_vat', 'vat', 'incl_vat'] as $field) {
            if ($doc[$field] === null) {
                $missing[] = $field;
            }
        }
        if ($missing) {
            $problems[] = 'not printed or not read: ' . implode(', ', $missing);
        }
        if ($problems) {
            return $problems;
        }

        $itemCents = 0;
        $units = 0;
        foreach ($doc['items'] as $n => $item) {
            if (self::cents($item['units'] * $item['unit_price']) !== self::cents($item['amount'])) {
                $problems[] = sprintf('item %d (%s): %d x %.2f is not %.2f', $n + 1, $item['barcode'], $item['units'], $item['unit_price'], $item['amount']);
            }
            $itemCents += self::cents($item['amount']);
            $units += $item['units'];
        }
        $promoCents = self::cents((float)$doc['promo_discount']);
        $total = self::cents($doc['total_amount']);
        $after = self::cents($doc['after_discount']);
        $incl = self::cents($doc['incl_vat']);
        if ($itemCents - $promoCents !== $total) {
            $problems[] = sprintf('item amounts %.2f minus promotion discount %.2f is not Total Amount %.2f', $itemCents / 100, $promoCents / 100, $total / 100);
        }
        if ($units !== $doc['shipped_units']) {
            $problems[] = sprintf('item units %d are not TOTAL SHIPPED %d', $units, $doc['shipped_units']);
        }
        if (abs(self::cents($doc['excl_vat']) + self::cents($doc['vat']) - $incl) > self::VAT_TOLERANCE_CENTS) {
            $problems[] = sprintf('Excluding VAT %.2f plus VAT %.2f is not Include VAT %.2f', $doc['excl_vat'], $doc['vat'], $doc['incl_vat']);
        }
        if ($total - self::cents($doc['total_discount']) !== $after) {
            $problems[] = sprintf('Total Amount %.2f minus Total Discount %.2f is not After Discount %.2f', $doc['total_amount'], $doc['total_discount'], $doc['after_discount']);
        }
        if ($after !== $incl) {
            $problems[] = sprintf('After Discount %.2f is not Include VAT %.2f', $doc['after_discount'], $doc['incl_vat']);
        }
        return $problems;
    }

    /**
     * The first row whose piece matches $label: ['label' => that piece, 'value' => the next piece when it matches
     * $value, else null]; null when no row has the label
     */
    private static function labelled(array $pagesRows, string $label, string $value): ?array
    {
        foreach ($pagesRows as $rows) {
            foreach ($rows as $row) {
                $at = self::find($row, [$label]);
                if ($at === null) {
                    continue;
                }
                $next = $row[$at + 1]['text'] ?? '';
                return ['label' => $row[$at]['text'], 'value' => preg_match($value, $next) ? $next : null];
            }
        }
        return null;
    }

    /**
     * The index of the first piece of the row that matches one of the patterns, or null
     */
    private static function find(array $row, array $patterns): ?int
    {
        foreach ($row as $i => $piece) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $piece['text'])) {
                    return $i;
                }
            }
        }
        return null;
    }

    private static function allAt(array $row, float $x): bool
    {
        foreach ($row as $piece) {
            if (abs($piece['x'] - $x) > 1.0) {
                return false;
            }
        }
        return true;
    }

    private static function joined(array $pieces): string
    {
        return implode(' ', array_column($pieces, 'text'));
    }

    private static function number(string $printed): float
    {
        return (float)str_replace(',', '', $printed);
    }

    private static function cents(float $amount): int
    {
        return (int)round($amount * 100);
    }

    /**
     * 06.04.2026 or 06-04-2026 (day first) as 2026-04-06, null for anything else
     */
    private static function isoDate(string $printed): ?string
    {
        if (!preg_match('/^(\d{2})[.\-](\d{2})[.\-](\d{4})$/', $printed, $m) || !checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return null;
        }
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    /**
     * The store number the ASN calls Dest Store No. (Store No on the ZCR sheets): the number that ends the store line,
     * without leading zeros (5052-ROBINSON CHIANGRAI 020123 is 20123); null when the line ends without one
     */
    private static function storeNo(string $customer): ?string
    {
        if (!preg_match('/^\d{4}-\S.*\s(\d{5,6})$/', $customer, $m)) {
            return null;
        }
        return ltrim($m[1], '0');
    }
}
