<?php

namespace App;

/**
 * Judges the credit notes of a Job against their ASN rows; the ASN is the source of truth, so only credit note
 * numbers the ASN lists are judged. Pure: rows in, verdicts out.
 *
 * A document is matched by the Tax Invoice key of its number (93251539-1 and 93251539-2 stay apart). Its lines are
 * its barcodes; quantity is the only check of a line, everything else (store, barcodes on one side only, amount, VAT)
 * belongs to the document and is told on every line of it. A document whose lines cannot be judged (no PDF, read
 * before the line reader existed, an Attachment Sheet, a reading that does not add up) carries one reason on every
 * line. A line is MATCH without a problem, a document takes its worst line.
 */
class CreditNoteReconciler
{
    /** How far, in baht, the PDF amount may be from the ASN amount: exact for now, the user may loosen it */
    public const AMOUNT_TOLERANCE = 0.00;
    public const VAT_TOLERANCE = 0.00;

    private const NO_PDF = 'ไม่พบใบลดหนี้นี้ใน PDF';
    private const BEFORE_READER = 'ใบลดหนี้นี้ดึงข้อมูลไว้ก่อนรุ่นที่อ่านรายการสินค้าและยอดท้ายใบ จึงยังเทียบไม่ได้';
    private const ATTACHMENT = 'ใบแนบ (Attachment Sheet) ไม่มีบาร์โค้ดและยอดท้ายใบให้เทียบ ตรวจด้วยตา';
    private const UNREADABLE = 'อ่านใบลดหนี้ไม่ครบ: %s';
    private const QUANTITY = 'จำนวนไม่ตรง (PDF: %d ชิ้น / ASN: %d ชิ้น)';
    private const STORE = 'รหัสสาขาไม่ตรง (PDF: %s / ASN: %s)';
    private const ASN_ONLY = 'บาร์โค้ด %s มีใน ASN แต่ไม่มีใน PDF (%d ชิ้น)';
    private const PDF_ONLY = 'บาร์โค้ด %s มีใน PDF แต่ไม่มีใน ASN (%d ชิ้น)';
    private const AMOUNT = 'ยอดรวมไม่ตรง (PDF: %s / ASN: %s)';
    private const VAT = 'VAT ไม่ตรง (PDF: ฿%s / ASN: ฿%s)';
    private const TOPICS = ', ';
    private const SHOWN = 3;

    /**
     * @param array $asnRecords CreditNoteAsnStore::records()
     * @param array $pdfDocs CreditNoteStore::docsWithItems()
     * @return array summary and results as job_cn_reconcile answers them, and documents: one verdict per ASN document
     *               (doc_no, store_no, source_files, units, amount, amount_kind, pages, status, problem) in ASN order
     */
    public static function reconcile(array $asnRecords, array $pdfDocs): array
    {
        if (!$asnRecords) {
            return ['summary' => null, 'results' => [], 'documents' => []];
        }
        $pdfByKey = [];
        foreach ($pdfDocs as $doc) {
            $pdfByKey += [self::key((string)$doc['doc_no']) => $doc];
        }

        $results = [];
        $documents = [];
        $groups = 0;
        foreach (self::asnDocuments($asnRecords) as $key => $asn) {
            $judged = self::judge($asn, $pdfByKey[$key] ?? null);
            $results = array_merge($results, $judged['rows']);
            $documents[] = $judged['document'];
            $groups += count($asn['lines']);
        }
        $statuses = array_count_values(array_column($results, 'status')) + ['MATCH' => 0, 'MISMATCH' => 0];

        return [
            'summary' => [
                'total_asn_records' => count($asnRecords),
                'total_asn_groups' => $groups,
                'matched' => $statuses['MATCH'],
                'mismatch' => $statuses['MISMATCH'],
                'total_compared' => count($results),
            ],
            'results' => $results,
            'documents' => $documents,
        ];
    }

    public static function key(string $docNo): string
    {
        return DashboardBuilder::invoiceKey($docNo);
    }

    /**
     * The ASN rows as documents, in the order of their first row: number, store and kind of the first row, the
     * amount on the document's basis in satang, its VAT (cost basis only) and its lines merged per barcode
     */
    private static function asnDocuments(array $records): array
    {
        $docs = [];
        foreach ($records as $row) {
            $key = self::key((string)$row['invoice_no']);
            if (!isset($docs[$key])) {
                $docs[$key] = [
                    'doc_no' => (string)$row['invoice_no'], 'store' => '', 'kind' => $row['amount_kind'],
                    'cost' => 0, 'vat' => 0, 'retail' => 0, 'lines' => [],
                ];
            }
            $doc = &$docs[$key];
            if ($doc['store'] === '') {
                $doc['store'] = trim((string)$row['dest_store_no']);
            }
            // The workbook repeats the document's Total Cost Ex VAT and Total VAT on every row; the first positive one stands for it
            if ($doc['cost'] === 0 && $row['total_cost_ex_vat'] > 0) {
                $doc['cost'] = self::cents($row['total_cost_ex_vat']);
            }
            if ($doc['vat'] === 0 && $row['total_vat'] > 0) {
                $doc['vat'] = self::cents($row['total_vat']);
            }
            $doc['retail'] += self::cents($row['quantity'] * $row['selling_price']);
            $barcode = self::digits((string)$row['ean_upc']);
            if (!isset($doc['lines'][$barcode])) {
                $doc['lines'][$barcode] = ['quantity' => 0, 'row_count' => 0, 'selling_price' => (float)$row['selling_price']];
            }
            $doc['lines'][$barcode]['quantity'] += (int)$row['quantity'];
            $doc['lines'][$barcode]['row_count']++;
            $doc['lines'][$barcode]['selling_price'] = min($doc['lines'][$barcode]['selling_price'], (float)$row['selling_price']);
            unset($doc);
        }
        foreach ($docs as &$doc) {
            $doc['amount'] = $doc['kind'] === 'cost' ? $doc['cost'] : $doc['retail'];
            $doc['vat'] = $doc['kind'] === 'cost' ? $doc['vat'] : null;
        }
        unset($doc);
        return $docs;
    }

    private static function judge(array $asn, ?array $pdf): array
    {
        $whole = self::wholeReason($pdf);
        if ($whole !== null) {
            $rows = [];
            foreach ($asn['lines'] as $barcode => $line) {
                $rows[] = self::row([$whole], $asn, $barcode, $line, $pdf, null, null, null);
            }
            return ['rows' => $rows, 'document' => self::document($asn, $pdf, $rows, [$whole], null)];
        }

        $pdfLines = self::pdfLines($pdf['items']);
        $asnOnly = array_diff_key($asn['lines'], $pdfLines);
        $pdfOnly = array_diff_key($pdfLines, $asn['lines']);
        $kindCost = $asn['kind'] === 'cost';
        $amountOk = self::agrees(self::cents($kindCost ? $pdf['excl_vat'] : $pdf['total_amount']), $asn['amount'], self::AMOUNT_TOLERANCE);
        $vatOk = $kindCost ? self::agrees(self::cents($pdf['vat']), $asn['vat'], self::VAT_TOLERANCE) : null;

        $problems = [];
        $pdfStore = ltrim((string)$pdf['store_no'], '0');
        $asnStore = ltrim($asn['store'], '0');
        if ($pdfStore !== '' && $asnStore !== '' && $pdfStore !== $asnStore) {
            $problems[] = sprintf(self::STORE, $pdfStore, $asnStore);
        }
        if ($asnOnly) {
            $problems[] = self::listed(self::ASN_ONLY, array_map(function (array $line): int {
                return $line['quantity'];
            }, $asnOnly));
        }
        if ($pdfOnly) {
            $problems[] = self::listed(self::PDF_ONLY, array_map(function (array $line): int {
                return $line['units'];
            }, $pdfOnly));
        }
        if (!$amountOk) {
            $problems[] = sprintf(self::AMOUNT, self::money($kindCost ? $pdf['excl_vat'] : $pdf['total_amount']), self::money(self::baht($asn['amount'])));
        }
        if ($vatOk === false) {
            $problems[] = sprintf(self::VAT, self::money($pdf['vat']), self::money(self::baht($asn['vat'])));
        }

        $rows = [];
        $lineTopics = [];
        foreach ($asn['lines'] as $barcode => $line) {
            $pdfLine = $pdfLines[$barcode] ?? null;
            $own = [];
            if ($pdfLine !== null && $pdfLine['units'] !== $line['quantity']) {
                $own[] = sprintf(self::QUANTITY, $pdfLine['units'], $line['quantity']);
                $lineTopics[] = 'บาร์โค้ด ' . $barcode . ': ' . $own[0];
            }
            $rows[] = self::row(array_merge($own, $problems), $asn, $barcode, $line, $pdf, $pdfLine, $amountOk, $vatOk);
        }
        foreach ($pdfOnly as $barcode => $pdfLine) {
            $rows[] = self::row($problems, $asn, $barcode, null, $pdf, $pdfLine, $amountOk, $vatOk);
        }
        $units = array_sum(array_column($pdfLines, 'units'));
        return ['rows' => $rows, 'document' => self::document($asn, $pdf, $rows, array_merge($lineTopics, $problems), $units)];
    }

    /**
     * The one reason every line of the document carries when its lines cannot be judged, null when they can
     */
    private static function wholeReason(?array $pdf): ?string
    {
        if ($pdf === null) {
            return self::NO_PDF;
        }
        if ($pdf['read_ok'] === null) {
            return self::BEFORE_READER;
        }
        if ($pdf['format_type'] === 'Attachment Sheet') {
            return self::ATTACHMENT;
        }
        return $pdf['read_ok'] ? null : sprintf(self::UNREADABLE, $pdf['read_note']);
    }

    /**
     * The PDF lines merged per barcode in printed order: pieces, the amount, unit price and description of the first line
     */
    private static function pdfLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $barcode = self::digits($item['barcode']);
            if (!isset($lines[$barcode])) {
                $lines[$barcode] = ['units' => 0, 'cents' => 0, 'unit_price' => $item['unit_price'], 'description' => $item['description']];
            }
            $lines[$barcode]['units'] += $item['units'];
            $lines[$barcode]['cents'] += self::cents($item['amount']);
        }
        return $lines;
    }

    private static function row(array $problems, array $asn, $barcode, ?array $asnLine, ?array $pdf, ?array $pdfLine, ?bool $amountOk, ?bool $vatOk): array
    {
        return [
            'status' => $problems ? 'MISMATCH' : 'MATCH',
            'reason' => implode(self::TOPICS, $problems),
            'action' => '',
            'amount_ok' => $amountOk,
            'vat_ok' => $vatOk,
            'amount_kind' => $asn['kind'],
            'source_files' => $pdf === null ? [] : [$pdf['filename']],
            'pdf' => $pdf === null ? null : [
                'doc_no' => $pdf['doc_no'],
                'barcode' => $pdfLine === null ? null : (string)$barcode,
                'description' => $pdfLine === null ? null : $pdfLine['description'],
                'store_no' => $pdf['store_no'],
                'units' => $pdfLine === null ? null : $pdfLine['units'],
                'unit_price' => $pdfLine === null ? null : $pdfLine['unit_price'],
                'amount' => $pdfLine === null ? null : self::baht($pdfLine['cents']),
                'format_type' => $pdf['format_type'],
                'pages' => $pdf['pages'],
                'doc_total_amount' => $pdf['total_amount'],
                'doc_excl_vat' => $pdf['excl_vat'],
                'doc_vat' => $pdf['vat'],
                'doc_incl_vat' => $pdf['incl_vat'],
            ],
            'asn' => [
                'doc_no' => $asn['doc_no'],
                'ean_upc' => $asnLine === null ? null : (string)$barcode,
                'dest_store_no' => $asn['store'],
                'quantity' => $asnLine === null ? null : $asnLine['quantity'],
                'row_count' => $asnLine === null ? null : $asnLine['row_count'],
                'selling_price' => $asnLine === null ? null : $asnLine['selling_price'],
                'doc_amount' => self::baht($asn['amount']),
                'total_vat' => $asn['vat'] === null ? null : self::baht($asn['vat']),
            ],
        ];
    }

    private static function document(array $asn, ?array $pdf, array $rows, array $topics, ?int $units): array
    {
        $wrong = in_array('MISMATCH', array_column($rows, 'status'), true);
        $shown = array_slice($topics, 0, self::SHOWN);
        $store = $asn['store'] !== '' ? $asn['store'] : (string)($pdf['store_no'] ?? '');
        return [
            'doc_no' => $asn['doc_no'],
            'store_no' => $store !== '' ? $store : null,
            'source_files' => $pdf === null ? [] : [$pdf['filename']],
            'units' => $units,
            'amount' => self::baht($asn['amount']),
            'amount_kind' => $asn['kind'],
            'pages' => $pdf === null ? null : self::pageCount((string)$pdf['pages']),
            'status' => $wrong ? 'MISMATCH' : 'MATCH',
            'problem' => $wrong ? implode(self::TOPICS, $shown) . (count($topics) > self::SHOWN ? ' และอีก ' . (count($topics) - self::SHOWN) . ' ประเด็น' : '') : '',
        ];
    }

    /**
     * One topic naming the barcodes of one side: the first three with their pieces, then how many more
     */
    private static function listed(string $sentence, array $piecesByBarcode): string
    {
        $named = [];
        foreach (array_slice($piecesByBarcode, 0, self::SHOWN, true) as $barcode => $pieces) {
            $named[] = sprintf($sentence, $barcode, $pieces);
        }
        $more = count($piecesByBarcode) - count($named);
        return implode(self::TOPICS, $named) . ($more > 0 ? ' และอีก ' . $more . ' รายการ' : '');
    }

    private static function agrees(int $pdfCents, int $asnCents, float $tolerance): bool
    {
        return abs($pdfCents - $asnCents) <= self::cents($tolerance);
    }

    /** "1-2" is two pages, "3" one */
    private static function pageCount(string $pages): int
    {
        $count = 0;
        foreach (explode(',', $pages) as $part) {
            $ends = array_map('intval', explode('-', trim($part)));
            $count += count($ends) === 2 ? $ends[1] - $ends[0] + 1 : 1;
        }
        return $count;
    }

    private static function cents(float $amount): int
    {
        return (int)round($amount * 100);
    }

    private static function baht(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    private static function digits(string $barcode): string
    {
        return ctype_digit($barcode) ? $barcode : preg_replace('/[^0-9]/', '', $barcode);
    }
}
