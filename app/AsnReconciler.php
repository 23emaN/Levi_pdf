<?php

namespace App;

use PDO;

/**
 * SAP ASN vs PDF Tax Invoice Reconciliation Engine
 * Based on ASN as the primary source of truth.
 */
class AsnReconciler
{
    /** Reason and action of a complete line, the user's wording; it is ready to print */
    public const COMPLETE = 'ข้อมูลถูกต้อง';

    /** Action of a line whose bill waits for the ASN (the user's wording) */
    public const FIX_ASN = 'แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่';

    /** Note of a line that waited for the fix button before its bill waited for the ASN. %s the problem it showed then */
    public const STILL_PDF = 'หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: %s';

    /** How far, in baht, the ASN Total Cost Ex VAT may be from the bill's Amt Excl VAT on the PDF (the user's rule) */
    public const AMOUNT_TOLERANCE = 1.00;

    public const VAT_TOLERANCE = 0.15;

    private const RIGHT_LINE = 'รายการนี้ถูกต้อง แต่';

    private const PDF_ONLY = 'บาร์โค้ด %s มีใน PDF แต่ไม่มีใน ASN (%d ชิ้น)';

    /**
     * A footer whose Total Amt Excluding VAT the bill's own lines do not give and the lines of another bill of the same
     * file do. %1$s that bill, %2$s the printed figure, %3$s the figure the bill's own lines give
     */
    private const FOOTER_OF_OTHER = 'ยอดรวมท้ายบิลเป็นของบิล %1$s (ยอดรวมก่อน VAT ท้ายบิล: %2$s / จากรายการของบิลนี้: %3$s)';

    private const FOOTER_NOT_OWN = 'ยอดรวมท้ายบิลไม่ตรงกับรายการของบิลนี้ (ยอดรวมก่อน VAT ท้ายบิล: %2$s / จากรายการของบิลนี้: %3$s)';

    /** Total Amt Include VAT over Total Amt Excluding VAT, the VAT(7%) the footer prints */
    private const WITH_VAT = 1.07;

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Reconcile Job PDF items against Job ASN records and, for the bills the Job's VBRP rows cover, against those too:
     * there SourceVerdict decides check by check which source is wrong, only a wrong PDF makes a line wrong and a wrong
     * ASN makes its bill wait for the ASN (waitForAsn). With no VBRP row that counts the result is the two-source one,
     * without the keys 'sources', summary 'pending_asn' and summary 'vbrp'.
     *
     * @param int $jobId
     * @param array $pdfItems
     * @param array|null $job
     * @return array
     */
    public function reconcile(int $jobId, array $pdfItems, ?array $job = null): array
    {
        $stmt = $this->db->prepare("
            SELECT id, job_id, invoice_no, dest_store_no, ean_upc, quantity,
                   selling_price, total_cost_ex_vat, total_vat, delivery_no
            FROM job_asn_records
            WHERE job_id = :job_id
        ");
        $stmt->execute([':job_id' => $jobId]);
        $asnRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($asnRecords)) {
            if ($job && ((int)($job['asn_matched_count'] ?? 0) !== 0 || (int)($job['asn_mismatch_count'] ?? 0) !== 0)) {
                $up = $this->db->prepare("
                    UPDATE jobs 
                    SET asn_matched_count = 0, asn_pending_fix_count = 0, asn_mismatch_count = 0 
                    WHERE id = :id
                ");
                $up->execute([':id' => $jobId]);
            }
            return [
                'summary' => null,
                'results' => []
            ];
        }

        $normInvCache = [];
        $getNormInv = function (string $inv) use (&$normInvCache): string {
            if (!isset($normInvCache[$inv])) {
                $normInvCache[$inv] = strtoupper(preg_replace('/[^A-Z0-9]/', '', $inv));
            }
            return $normInvCache[$inv];
        };

        $getRootInv = function (string $inv): string {
            $parts = explode('-', $inv);
            return strtoupper(preg_replace('/[^A-Z0-9]/', '', $parts[0]));
        };

        $vbrp = VbrpFigures::forJob($this->db, $jobId);
        $fixPlan = new FixPlan($asnRecords, $pdfItems);
        $pdfItems = $fixPlan->applyTo($pdfItems);

        // 1. Analyze PDF items and group by invoice and pages
        $pdfInvoiceMeta = [];
        $pdfInvoicePages = [];

        foreach ($pdfItems as $pItem) {
            $rawInv = (string)($pItem['tax_invoice'] ?? '');
            $invKey = $getNormInv($rawInv);
            if ($invKey === '') {
                continue;
            }

            $pNo = (int)($pItem['page_no'] ?? 1);
            $hasFooter = (isset($pItem['amt_excl_vat']) && $pItem['amt_excl_vat'] !== 'Null' && $pItem['amt_excl_vat'] !== '-');

            if (!isset($pdfInvoicePages[$invKey])) {
                $pdfInvoicePages[$invKey] = [
                    'pages' => [],
                    'footer_pages' => []
                ];
            }
            if (!in_array($pNo, $pdfInvoicePages[$invKey]['pages'], true)) {
                $pdfInvoicePages[$invKey]['pages'][] = $pNo;
            }
            if ($hasFooter && !in_array($pNo, $pdfInvoicePages[$invKey]['footer_pages'], true)) {
                $pdfInvoicePages[$invKey]['footer_pages'][] = $pNo;
            }

            if (!isset($pdfInvoiceMeta[$invKey])) {
                $pdfInvoiceMeta[$invKey] = [
                    'tax_invoice' => $rawInv,
                    'amt_excl_vat' => 0.0,
                    'vat_amount' => 0.0,
                    'total_amount' => 0.0,
                    'store_code' => self::extractStoreCode($pItem['ship_to_party'] ?? '', $pItem['address'] ?? '')
                ];
            }

            $rawExcl = $pItem['amt_excl_vat'] ?? '';
            if ($rawExcl !== '' && $rawExcl !== 'Null') {
                $excl = $this->parseMoney($rawExcl);
                if ($excl > 0) {
                    $pdfInvoiceMeta[$invKey]['amt_excl_vat'] = $excl;
                }
            }
            $rawVat = $pItem['vat_amount'] ?? '';
            if ($rawVat !== '' && $rawVat !== 'Null') {
                $vat = $this->parseMoney($rawVat);
                if ($vat > 0) {
                    $pdfInvoiceMeta[$invKey]['vat_amount'] = $vat;
                }
            }
            $rawTot = $pItem['total_amount'] ?? '';
            if ($rawTot !== '' && $rawTot !== 'Null') {
                $totAmt = $this->parseMoney($rawTot);
                if ($totAmt > 0) {
                    $pdfInvoiceMeta[$invKey]['total_amount'] = $totAmt;
                }
            }
        }

        // 2. Group PDF items by (invKey_barcode) and index by (rootInv_barcode)
        $pdfGroups = [];
        $pdfRootBarcodeMap = [];

        foreach ($pdfItems as $pItem) {
            $rawInv = (string)($pItem['tax_invoice'] ?? '');
            $invKey = $getNormInv($rawInv);
            $rootInv = $getRootInv($rawInv);

            $rawBar = (string)($pItem['barcode'] ?? '');
            $barClean = ctype_digit($rawBar) ? $rawBar : preg_replace('/[^0-9]/', '', $rawBar);
            $key = $invKey . '_' . $barClean;

            $meta = $pdfInvoiceMeta[$invKey] ?? [
                'amt_excl_vat' => $this->parseMoney($pItem['amt_excl_vat'] ?? ''),
                'vat_amount' => $this->parseMoney($pItem['vat_amount'] ?? ''),
                'total_amount' => $this->parseMoney($pItem['total_amount'] ?? ''),
                'store_code' => ''
            ];

            if (!isset($pdfGroups[$key])) {
                $mergedPdf = $pItem;
                $mergedPdf['units'] = (int)($pItem['units'] ?? 0);
                $mergedPdf['amount'] = (float)($pItem['amount'] ?? 0);
                $mergedPdf['row_count'] = 1;
                $mergedPdf['norm_inv'] = $invKey;
                $mergedPdf['store_code'] = $meta['store_code'];
                $mergedPdf['inv_amt_excl_vat'] = $meta['amt_excl_vat'];
                $mergedPdf['inv_vat_amount'] = $meta['vat_amount'];
                if ($meta['amt_excl_vat'] > 0) {
                    $mergedPdf['amt_excl_vat'] = number_format($meta['amt_excl_vat'], 2);
                }
                if ($meta['vat_amount'] > 0) {
                    $mergedPdf['vat_amount'] = number_format($meta['vat_amount'], 2);
                }
                $pdfGroups[$key] = $mergedPdf;
            } else {
                $pdfGroups[$key]['units'] += (int)($pItem['units'] ?? 0);
                $pdfGroups[$key]['amount'] += (float)($pItem['amount'] ?? 0);
                $pdfGroups[$key]['row_count']++;
            }

            // Index by root and barcode for suffix recovery
            $rootKey = $rootInv . '_' . $barClean;
            if (!isset($pdfRootBarcodeMap[$rootKey])) {
                $pdfRootBarcodeMap[$rootKey] = $pdfGroups[$key];
            }
        }

        // 3. Group ASN records by (invKey_barcode)
        $asnGroups = [];
        foreach ($asnRecords as $asn) {
            $rawInv = (string)($asn['invoice_no'] ?? '');
            $invKey = $getNormInv($rawInv);
            $rawEan = (string)($asn['ean_upc'] ?? '');
            $barClean = ctype_digit($rawEan) ? $rawEan : preg_replace('/[^0-9]/', '', $rawEan);
            $key = $invKey . '_' . $barClean;

            $totalVat = (float)($asn['total_vat'] ?? 0);
            $delivNo = trim((string)($asn['delivery_no'] ?? ''));

            if (!isset($asnGroups[$key])) {
                $mergedAsn = $asn;
                $mergedAsn['norm_inv'] = $invKey;
                $mergedAsn['quantity'] = (int)($asn['quantity'] ?? 0);
                $mergedAsn['selling_price'] = (float)($asn['selling_price'] ?? 0);
                $mergedAsn['total_cost_ex_vat'] = (float)($asn['total_cost_ex_vat'] ?? 0);
                $mergedAsn['total_vat'] = $totalVat;
                $mergedAsn['row_count'] = 1;
                $mergedAsn['delivery_nos'] = $delivNo !== '' ? [$delivNo] : [];
                $asnGroups[$key] = $mergedAsn;
            } else {
                $asnGroups[$key]['quantity'] += (int)($asn['quantity'] ?? 0);
                $asnGroups[$key]['row_count']++;
                if ((float)($asn['selling_price'] ?? 0) < $asnGroups[$key]['selling_price']) {
                    $asnGroups[$key]['selling_price'] = (float)$asn['selling_price'];
                }
                if ($delivNo !== '' && !in_array($delivNo, $asnGroups[$key]['delivery_nos'], true)) {
                    $asnGroups[$key]['delivery_nos'][] = $delivNo;
                }
            }
        }

        // 3b. The barcodes the PDF of an ASN bill prints, on the rows as the fix leaves them, that the ASN lines of the
        // bill do not list: each makes every line of the bill wrong, unless VBRP lists the same pieces (then the ASN lacks it)
        $asnInvoices = array_flip(array_column($asnGroups, 'norm_inv'));
        $pdfOnly = []; // invKey => barcode => pieces, in the order the PDF prints them
        $billNotes = []; // invKey => notes every line of the bill carries
        foreach ($pdfGroups as $key => $pItem) {
            if (isset($asnInvoices[$pItem['norm_inv']]) && !isset($asnGroups[$key])) {
                $invKey = $pItem['norm_inv'];
                $rawBar = (string)($pItem['barcode'] ?? '');
                $barClean = ctype_digit($rawBar) ? $rawBar : preg_replace('/[^0-9]/', '', $rawBar);
                $pieces = (int)$pItem['units'];
                if ($vbrp->covers($invKey) && SourceVerdict::judge($pieces, 0.0, $vbrp->line($invKey, $barClean)['qty'], 0.0)['pdf'] !== SourceVerdict::WRONG) {
                    $billNotes[$invKey][] = sprintf(SourceVerdict::ASN_LACKS, $barClean, $pieces);
                } else {
                    $pdfOnly[$invKey][$barClean] = $pieces;
                }
            }
        }
        $billProblems = [];
        foreach ($pdfOnly as $invKey => $barcodes) {
            $problems = [];
            foreach (array_slice($barcodes, 0, 3, true) as $barClean => $pieces) {
                $problems[] = sprintf(self::PDF_ONLY, $barClean, $pieces);
            }
            $billProblems[$invKey] = implode(FixLog::TOPICS, $problems) . self::andMore(count($barcodes), count($problems));
        }
        foreach (self::footerProblems($pdfItems, $getNormInv) as $invKey => $problem) {
            $billProblems[$invKey] = isset($billProblems[$invKey]) ? $billProblems[$invKey] . FixLog::TOPICS . $problem : $problem;
        }

        // 3c. The three sources of every ASN line of a bill VBRP covers. The PDF gives the pieces of the line (0 when it
        // lacks it) and the totals of the bill, nothing for a line found under another number of its root (Case B below)
        $sources = [];
        foreach ($asnGroups as $key => $asnEntry) {
            $invKey = $asnEntry['norm_inv'];
            if (!$vbrp->covers($invKey)) {
                continue;
            }
            $barClean = preg_replace('/[^0-9]/', '', (string)$asnEntry['ean_upc']);
            $caseB = !isset($pdfGroups[$key]) && isset($pdfRootBarcodeMap[$getRootInv((string)$asnEntry['invoice_no']) . '_' . $barClean]) && !isset($billProblems[$invKey]);
            $meta = $caseB ? [] : ($pdfInvoiceMeta[$invKey] ?? []);
            $line = $vbrp->line($invKey, $barClean);
            $bill = $vbrp->bill($invKey);
            $sources[$key] = new SourceVerdict([
                'qty' => ['pdf' => $caseB ? null : (float)($pdfGroups[$key]['units'] ?? 0), 'asn' => (float)$asnEntry['quantity'], 'vbrp' => $line['qty']],
                'amount' => ['pdf' => SourceVerdict::positive((float)($meta['amt_excl_vat'] ?? 0)), 'asn' => SourceVerdict::positive((float)$asnEntry['total_cost_ex_vat']), 'vbrp' => SourceVerdict::positive($bill['cost'])],
                'vat' => ['pdf' => SourceVerdict::positive((float)($meta['vat_amount'] ?? 0)), 'asn' => SourceVerdict::positive((float)$asnEntry['total_vat']), 'vbrp' => SourceVerdict::positive($bill['vat'])],
            ], $line['price']);
        }

        // 4. Reconcile anchored on ASN (ASN as Single Source of Truth)
        $results = [];
        $lineNotes = []; // key => check => the note that stands for that check's notes

        // Per ASN invoice: lines whose page is numbered under another suffix (cannot be judged on totals yet)
        // and lines that are wrong themselves (these make the whole bill fail); in a covered bill only the PDF's pieces
        // judged wrong make a line a culprit
        $shiftedInvoices = [];
        $culprits = [];
        foreach ($asnGroups as $key => $asnEntry) {
            $barClean = ctype_digit($asnEntry['ean_upc']) ? $asnEntry['ean_upc'] : preg_replace('/[^0-9]/', '', (string)$asnEntry['ean_upc']);
            $source = $sources[$key] ?? null;
            if (isset($pdfGroups[$key])) {
                $pdfUnits = (int)$pdfGroups[$key]['units'];
                $asnQty = (int)$asnEntry['quantity'];
                if ($source ? $source->pdfWrong('qty') : $pdfUnits !== $asnQty) {
                    $culprits[$asnEntry['norm_inv']][] = "{$barClean} จำนวนไม่ตรง (PDF: {$pdfUnits} / ASN: {$asnQty})";
                }
            } elseif (isset($pdfRootBarcodeMap[$getRootInv((string)$asnEntry['invoice_no']) . '_' . $barClean])) {
                $shiftedInvoices[$asnEntry['norm_inv']] = true;
            } elseif ($source === null || $source->pdfWrong('qty')) {
                $culprits[$asnEntry['norm_inv']][] = "{$barClean} ไม่พบใน PDF";
            }
        }

        foreach ($asnGroups as $key => $asnEntry) {
            $asnInv = (string)($asnEntry['invoice_no'] ?? '');
            $invKey = $asnEntry['norm_inv'];
            $rootInv = $getRootInv($asnInv);
            $barClean = ctype_digit($asnEntry['ean_upc']) ? $asnEntry['ean_upc'] : preg_replace('/[^0-9]/', '', (string)$asnEntry['ean_upc']);
            $rootKey = $rootInv . '_' . $barClean;

            $asnQty = (int)$asnEntry['quantity'];
            $asnSellPrice = (float)$asnEntry['selling_price'];
            $asnCostExcl = (float)$asnEntry['total_cost_ex_vat'];
            $asnVat = (float)($asnEntry['total_vat'] ?? 0);
            $asnStore = trim((string)($asnEntry['dest_store_no'] ?? ''));

            // In a covered bill a check fails the line only when it judges the PDF wrong; a negative ASN price, a figure
            // of the ASN alone, fails none there
            $source = $sources[$key] ?? null;
            $isNegPrice = $source === null && $asnSellPrice < 0;
            $caseB = isset($pdfRootBarcodeMap[$rootKey]) && !isset($billProblems[$invKey]);
            // An ASN line its PDF lacks that VBRP does not bill either is no fault of the PDF: judged as a line of 0 pieces
            $absentRightly = !isset($pdfGroups[$key]) && !$caseB && $source !== null && !$source->pdfWrong('qty');
            if ($absentRightly && !$vbrp->lists($invKey, $barClean)) {
                $lineNotes[$key] = ['qty' => SourceVerdict::ASN_ONLY];
            }

            // Case A: Direct key match in PDF
            if (isset($pdfGroups[$key]) || $absentRightly) {
                $meta = $pdfInvoiceMeta[$invKey] ?? ['store_code' => '', 'amt_excl_vat' => 0.0, 'vat_amount' => 0.0];
                $pItem = $pdfGroups[$key] ?? ['units' => 0, 'store_code' => $meta['store_code'], 'inv_amt_excl_vat' => $meta['amt_excl_vat'], 'inv_vat_amount' => $meta['vat_amount']];
                $pdfUnits = (int)$pItem['units'];
                $pdfStore = trim((string)($pItem['store_code'] ?? ''));
                $pdfExcl = (float)$pItem['inv_amt_excl_vat'];
                $pdfVat = (float)$pItem['inv_vat_amount'];

                $qtyMatch = $source ? !$source->pdfWrong('qty') : ($pdfUnits === $asnQty);
                $storeMatch = ($pdfStore === '' || $asnStore === '' || $pdfStore === $asnStore);
                $amountOk = self::amountAgrees($pdfExcl, $asnCostExcl);
                $amountRight = $source ? !$source->pdfWrong('amount') : $amountOk !== false;
                $vatMatch = $source ? !$source->pdfWrong('vat') : ($pdfVat <= 0 || $asnVat <= 0 || abs($pdfVat - $asnVat) <= self::VAT_TOLERANCE);
                $lineOk = $qtyMatch && !$isNegPrice && $storeMatch;
                $billProblem = $billProblems[$invKey] ?? '';

                // Check duplicate footer on continuation pages
                $hasDuplicateFooter = false;
                if (isset($pdfInvoicePages[$invKey])) {
                    $pgInfo = $pdfInvoicePages[$invKey];
                    if (count($pgInfo['pages']) > 1 && count($pgInfo['footer_pages']) > 1) {
                        $hasDuplicateFooter = true;
                    }
                }

                $culprit = '';
                if ($hasDuplicateFooter && $billProblem === '') {
                    $status = BillStatus::PENDING_FIX;
                    $reason = 'พบข้อมูลส่วนท้ายบิลซ้ำในหน้าต่อเนื่อง';
                    $action = 'ลบยอดรวมที่ซ้ำ, แยกบิลนี้ออกมาเป็นอีกไฟล์';
                    $canFix = true;
                    $amountOk = null;
                } elseif ($lineOk && $billProblem === '' && $amountRight && $vatMatch) {
                    $status = BillStatus::MATCH;
                    $reason = self::COMPLETE;
                    $action = self::COMPLETE;
                    $canFix = false;
                } elseif ($lineOk && $billProblem === '' && isset($shiftedInvoices[$invKey])) {
                    $status = BillStatus::PENDING_FIX;
                    $reason = 'เลข Tax Invoice ของบางหน้าในบิลนี้คลาดเคลื่อน จึงยังเทียบยอดรวมไม่ได้';
                    $action = 'รวมหน้าต่อเนื่องเข้ากับบิลนี้, เทียบยอดอีกครั้ง';
                    $canFix = true;
                    $amountOk = null;
                } else {
                    // A wrong line names its problem only: the user checks it at the source, no solution is offered
                    $status = BillStatus::MISMATCH;
                    $reasons = [];
                    if (!$qtyMatch) {
                        $reasons[] = $source ? $source->problem('qty') : "จำนวนไม่ตรง (PDF: {$pdfUnits} ชิ้น / ASN: {$asnQty} ชิ้น)";
                    }
                    if ($isNegPrice) {
                        $pAsn = number_format($asnSellPrice, 2);
                        $reasons[] = "Selling Price ติดลบใน ASN (฿{$pAsn})";
                    }
                    if (!$storeMatch) {
                        $reasons[] = "รหัสสาขาไม่ตรง (PDF: {$pdfStore} / ASN: {$asnStore})";
                    }
                    if ($billProblem !== '') {
                        $reasons[] = $billProblem;
                    }
                    if (!$amountRight) {
                        $reasons[] = $source ? $source->problem('amount') : self::amountProblem($pdfExcl, $asnCostExcl);
                    }
                    if (!$vatMatch) {
                        $vPdf = number_format($pdfVat, 2);
                        $vAsn = number_format($asnVat, 2);
                        $reasons[] = $source ? $source->problem('vat') : "VAT ไม่ตรง (PDF: ฿{$vPdf} / ASN: ฿{$vAsn})";
                    }
                    $reason = implode(FixLog::TOPICS, $reasons);
                    $action = '';
                    if ($lineOk && !empty($culprits[$invKey])) {
                        $named = array_slice($culprits[$invKey], 0, 3);
                        $culprit = 'บิลผิดพลาดเพราะบาร์โค้ด ' . implode(', ', $named) . self::andMore(count($culprits[$invKey]), count($named))
                            . ' จึงทำให้ยอดรวมบิลไม่ตรง (' . $reason . ')';
                        $reason = self::RIGHT_LINE . $culprit;
                    }
                    $canFix = false;
                }

                $results[] = [
                    'status' => $status,
                    'status_label' => BillStatus::label($status),
                    'reason' => $reason,
                    'action' => $action,
                    'can_fix' => $canFix,
                    'amount_ok' => $amountOk,
                    'culprit' => $culprit,
                    'pdf' => $pdfGroups[$key] ?? null,
                    'asn' => $asnEntry
                ];
            }
            // Case B: Mismatched suffix under the same invoice root (e.g. Page 2 printed -2 instead of -1); with a problem
            // of its own PDF the bill is wrong whatever the fix does, so the line is told as missing below
            elseif ($caseB) {
                $pItem = $pdfRootBarcodeMap[$rootKey];
                $pdfInv = (string)($pItem['tax_invoice'] ?? '');

                $status = BillStatus::PENDING_FIX;

                $results[] = [
                    'status' => $status,
                    'status_label' => BillStatus::label($status),
                    'reason' => "เลขที่พิมพ์ในบิล PDF คือ {$pdfInv} แต่ใน ASN เป็น {$asnInv}",
                    'action' => 'รวมหน้าต่อเนื่องเข้ากับบิลนี้, แยกบิลนี้ออกมาเป็นอีกไฟล์',
                    'can_fix' => true,
                    'amount_ok' => null,
                    'culprit' => '',
                    'pdf' => $pItem,
                    'asn' => $asnEntry
                ];
            }
            // Case C: Barcode not found in PDF at all
            else {
                $status = BillStatus::MISMATCH;
                $reason = "มีรายการในไฟล์ ASN แต่ไม่พบไฟล์ PDF ของบิลนี้ [PDF ไม่ออก หรือบิลตกหล่น]";
                if (isset($billProblems[$invKey])) {
                    $reason .= FixLog::TOPICS . $billProblems[$invKey];
                }

                $results[] = [
                    'status' => $status,
                    'status_label' => BillStatus::label($status),
                    'reason' => $reason,
                    'action' => '',
                    'can_fix' => false,
                    'amount_ok' => null,
                    'culprit' => '',
                    'pdf' => null,
                    'asn' => $asnEntry
                ];
            }
        }

        // A wrong line is wrong after the fix too, so the plan knows which bills cannot be said to match then
        $wrongBills = [];
        foreach ($results as $row) {
            if ($row['status'] === BillStatus::MISMATCH || (!$vbrp->isEmpty() && !$vbrp->covers($row['asn']['norm_inv']))) {
                $wrongBills[$row['asn']['norm_inv']] = true;
            }
        }
        $planned = $fixPlan->describe(array_unique(array_column($asnRecords, 'invoice_no')), (new FixLog($this->db))->splitDone($jobId), $wrongBills);
        foreach ($results as &$row) {
            $row = self::beforeFix($row, $planned[(string)($row['asn']['invoice_no'] ?? '')] ?? ['problem' => '', 'reason' => '', 'action' => '']);
            if (!$vbrp->isEmpty()) {
                $key = self::lineKey($row['asn']);
                if (isset($sources[$key])) {
                    $row['sources'] = $sources[$key]->payload($row['status'], $row['pdf'] !== null, array_merge($sources[$key]->notes($lineNotes[$key] ?? []), $billNotes[$row['asn']['norm_inv']] ?? []));
                } else {
                    $invKey = $row['asn']['norm_inv'];
                    $pItem = $row['pdf'];
                    $meta = $pdfInvoiceMeta[$invKey] ?? [];
                    $twoWay = new SourceVerdict([
                        'qty' => ['pdf' => $pItem === null ? null : (float)($pItem['units'] ?? 0), 'asn' => (float)($row['asn']['quantity'] ?? 0), 'vbrp' => null],
                        'amount' => ['pdf' => SourceVerdict::positive((float)($meta['amt_excl_vat'] ?? 0)), 'asn' => SourceVerdict::positive((float)($row['asn']['total_cost_ex_vat'] ?? 0)), 'vbrp' => null],
                        'vat' => ['pdf' => SourceVerdict::positive((float)($meta['vat_amount'] ?? 0)), 'asn' => SourceVerdict::positive((float)($row['asn']['total_vat'] ?? 0)), 'vbrp' => null],
                    ], null);
                    $payload = $twoWay->payload($row['status'], $pItem !== null, []);
                    if ((float)($row['asn']['selling_price'] ?? 0) < 0) {
                        $payload['overall']['asn'] = SourceVerdict::WRONG;
                    }
                    $row['sources'] = $payload;
                    if ($row['status'] === BillStatus::MISMATCH) {
                        $row['reason'] = 'ไม่พบใน VBRP' . ($row['reason'] !== '' ? FixLog::TOPICS . $row['reason'] : '');
                        if ($row['culprit'] !== '') {
                            $row['culprit'] = 'ไม่พบใน VBRP' . FixLog::TOPICS . $row['culprit'];
                        }
                    } else {
                        $row['status'] = BillStatus::MISMATCH;
                        $row['status_label'] = BillStatus::label(BillStatus::MISMATCH);
                        $row['reason'] = 'ไม่พบใน VBRP';
                        $row['action'] = '';
                        $row['can_fix'] = false;
                        $row['culprit'] = '';
                    }
                }
            }
        }
        unset($row);
        if (!$vbrp->isEmpty()) {
            $results = self::waitForAsn($results, $sources, $lineNotes, $billNotes);
        }
        $count = array_fill_keys(BillStatus::codes(), 0);
        foreach ($results as $row) {
            $count[$row['status']]++;
        }

        // 5. Update jobs table with new counts
        $up = $this->db->prepare("
            UPDATE jobs 
            SET asn_matched_count = :m,
                asn_pending_fix_count = :pf,
                asn_mismatch_count = :mis,
                asn_reconciled = 1,
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        $up->execute([
            ':m' => $count['MATCH'],
            ':pf' => $count['PENDING_FIX'],
            ':mis' => $count['MISMATCH'],
            ':id' => $jobId
        ]);

        $summary = [
            'total_asn_records' => count($asnRecords),
            'total_asn_groups' => count($asnGroups),
            'matched' => $count['MATCH'],
            'pending_fix' => $count['PENDING_FIX'],
            'mismatch' => $count['MISMATCH'],
            'total_compared' => count($results)
        ];
        if (!$vbrp->isEmpty()) {
            $covered = count(array_filter(array_keys($asnInvoices), [$vbrp, 'covers']));
            $summary['pending_asn'] = $count[BillStatus::PENDING_ASN];
            $summary['vbrp'] = ['covered_bills' => $covered, 'uncovered_bills' => count($asnInvoices) - $covered];
        }
        return [
            'summary' => $summary,
            'results' => $results
        ];
    }

    private static function lineKey(array $asn): string
    {
        return $asn['norm_inv'] . '_' . preg_replace('/[^0-9]/', '', (string)$asn['ean_upc']);
    }

    /**
     * In a bill VBRP covers whose ASN is wrong (a check judges the ASN wrong on one of its lines, or the ASN lacks a
     * barcode the PDF and VBRP have) every line that matches or waits for the fix button waits for the ASN instead, as
     * the user decided on 2026-10-28: the bill cannot be fixed or printed until a corrected ASN is imported. A wrong line
     * stays wrong. The problem names the bill's ASN faults, quantities first (with their barcode when the bill has more
     * than one line), then amount, VAT and the barcodes the ASN lacks; the notes leave out what the problem names, and a
     * line that waited for the fix button says it will wait again once the ASN is right. row.sources keeps the verdicts
     * of the line's own status, so a line that waits is not wrong in its PDF.
     *
     * @param array $sources line key => SourceVerdict, for the lines of covered bills
     * @param array $lineNotes line key => check => the note that stands for that check's notes
     * @param array $billNotes invoice key => the ASN_LACKS notes of the bill
     */
    private static function waitForAsn(array $results, array $sources, array $lineNotes, array $billNotes): array
    {
        $bills = [];
        foreach ($results as $i => $row) {
            $bills[$row['asn']['norm_inv']][] = $i;
        }
        foreach ($bills as $invKey => $lines) {
            $problems = [];
            foreach (array_keys(SourceVerdict::CHECKS) as $check) {
                foreach ($lines as $i) {
                    $source = $sources[self::lineKey($results[$i]['asn'])] ?? null;
                    if ($source !== null && $source->asnWrong($check)) {
                        $barcode = $check === 'qty' && count($lines) > 1 ? 'บาร์โค้ด ' . trim((string)$results[$i]['asn']['ean_upc']) . ': ' : '';
                        $problems[] = $barcode . $source->asnProblem($check);
                    }
                }
            }
            $problems = array_values(array_unique(array_merge($problems, $billNotes[$invKey] ?? [])));
            if (empty($problems)) {
                continue;
            }
            foreach ($lines as $i) {
                $row = $results[$i];
                if ($row['status'] === BillStatus::MISMATCH) {
                    continue;
                }
                $source = $sources[self::lineKey($row['asn'])];
                $notes = $source->notes($lineNotes[self::lineKey($row['asn'])] ?? [], array_filter(array_keys(SourceVerdict::CHECKS), [$source, 'asnWrong']));
                if ($row['status'] === BillStatus::PENDING_FIX) {
                    $notes[] = sprintf(self::STILL_PDF, $row['reason']);
                }
                $row['status'] = BillStatus::PENDING_ASN;
                $row['status_label'] = BillStatus::label(BillStatus::PENDING_ASN);
                $row['reason'] = implode(FixLog::TOPICS, $problems);
                $row['action'] = self::FIX_ASN;
                $row['can_fix'] = BillStatus::canFix(BillStatus::PENDING_ASN);
                $row['culprit'] = '';
                $row['sources']['notes'] = $notes;
                $results[$i] = $row;
            }
        }
        return $results;
    }

    /**
     * A line as it reads before the fix, from its verdict on the rows as the fix leaves them
     *
     * The verdict stands for a bill the fix leaves alone, and for a line that will be wrong: its content reason and
     * action are what they will be after the fix. A line that waits already takes the plan's page problem and solution
     * where the plan has them, else keeps the general wording. A line that will match waits for the fix, with the
     * plan's reason (the page problem, or the split still to do) and the plan's solution.
     *
     * @param array $plan ['problem' => string, 'reason' => string, 'action' => string] of the line's invoice, from FixPlan::describe()
     */
    private static function beforeFix(array $line, array $plan): array
    {
        if ($line['status'] === BillStatus::PENDING_FIX) {
            $line['reason'] = $plan['problem'] !== '' ? $plan['problem'] : $line['reason'];
            $line['action'] = $plan['action'] !== '' ? $plan['action'] : $line['action'];
            return $line;
        }
        if ($plan['reason'] === '' || $line['status'] !== BillStatus::MATCH) {
            return $line;
        }
        return [
            'status' => BillStatus::PENDING_FIX,
            'status_label' => BillStatus::label(BillStatus::PENDING_FIX),
            'reason' => $plan['reason'],
            'action' => $plan['action'],
            'can_fix' => true,
        ] + $line;
    }

    /**
     * Whether the bill's Amt Excl VAT on the PDF and the ASN Total Cost Ex VAT agree; null when either is missing or not
     * positive, which is not judged
     */
    private static function amountAgrees(float $pdfExcl, float $asnCost): ?bool
    {
        if ($pdfExcl <= 0 || $asnCost <= 0) {
            return null;
        }
        // Compared in satang: 13,028.05 - 13,027.05 is a hair above 1.00 as a float
        return round(abs($pdfExcl - $asnCost), 2) <= self::AMOUNT_TOLERANCE;
    }

    /**
     * The amount problem in the user's wording: both totals, no side named as higher and no difference, no baht sign
     */
    private static function amountProblem(float $pdfExcl, float $asnCost): string
    {
        return sprintf('ยอดรวมไม่ตรง (PDF: %s / ASN: %s)', number_format($pdfExcl, 2), number_format($asnCost, 2));
    }

    /**
     * Per bill, on the rows as the fix leaves them, whether the Total Amt Excluding VAT of its footer comes from its own
     * lines: (their amounts - Promotion Discount) x (1 - the footer's Total Discount %) / 1.07, within AMOUNT_TOLERANCE
     * (the PDF's own rounding stays within 0.07 on the sample). A bill's lines are those of all its pages, its footer the
     * last one its rows carry; a footer without a readable discount, percentage or positive Amt Excl VAT is not judged.
     * When the figure is not the bill's own, the first bill of the same file whose lines give it is named.
     *
     * @param array $pdfItems rows as FixPlan::applyTo() returns them, in job_items.id order
     * @param callable $invoiceKey invoice number => the key the reconciler groups bills by
     * @return array invoice key => problem sentence, for the bills whose footer is not their own
     */
    private static function footerProblems(array $pdfItems, callable $invoiceKey): array
    {
        $bills = [];
        $files = []; // filename => invoice keys, in the order the file prints the bills
        foreach ($pdfItems as $item) {
            $key = $invoiceKey((string)($item['tax_invoice'] ?? ''));
            if ($key === '') {
                continue;
            }
            $file = (string)($item['filename'] ?? '');
            if (!isset($bills[$key])) {
                $bills[$key] = ['invoice' => (string)$item['tax_invoice'], 'lines' => 0.0, 'footer' => null, 'files' => []];
            }
            $bills[$key]['lines'] += (float)self::figure($item['amount'] ?? '');
            $bills[$key]['files'][$file] = true;
            $files[$file][$key] = true;
            $excl = self::figure($item['amt_excl_vat'] ?? '');
            if ($excl !== null && $excl > 0) {
                $promo = self::figure($item['promotion_discount'] ?? '');
                $percent = preg_match('/^(\d+(?:\.\d+)?)\s*%$/', trim((string)($item['discount_percent'] ?? '')), $m) ? (float)$m[1] : null;
                $bills[$key]['footer'] = $promo === null || $percent === null ? null : ['promo' => $promo, 'percent' => $percent, 'excl' => $excl];
            }
        }

        $problems = [];
        foreach ($bills as $key => $bill) {
            $footer = $bill['footer'];
            if ($footer === null || self::footerFits($footer, $bill['lines'])) {
                continue;
            }
            $owner = null;
            foreach (array_keys($bill['files']) as $file) {
                foreach (array_keys($files[$file]) as $other) {
                    if ($owner === null && $other !== $key && self::footerFits($footer, $bills[$other]['lines'])) {
                        $owner = $bills[$other]['invoice'];
                    }
                }
            }
            $problems[$key] = sprintf(
                $owner === null ? self::FOOTER_NOT_OWN : self::FOOTER_OF_OTHER,
                $owner,
                number_format($footer['excl'], 2),
                number_format(self::fromLines($footer, $bill['lines']), 2)
            );
        }
        return $problems;
    }

    private static function fromLines(array $footer, float $lines): float
    {
        return round(($lines - $footer['promo']) * (1 - $footer['percent'] / 100) / self::WITH_VAT, 2);
    }

    private static function footerFits(array $footer, float $lines): bool
    {
        return round(abs(self::fromLines($footer, $lines) - $footer['excl']), 2) <= self::AMOUNT_TOLERANCE;
    }

    private static function andMore(int $total, int $shown): string
    {
        return $total > $shown ? ' และอีก ' . ($total - $shown) . ' รายการ' : '';
    }

    /**
     * Status of every ASN invoice number: the worst status among its lines
     *
     * @param array $results Rows returned by reconcile()
     * @return array invoice number => a BillStatus code
     */
    public static function invoiceStatuses(array $results): array
    {
        $lines = [];
        foreach ($results as $row) {
            $invoice = trim((string)($row['asn']['invoice_no'] ?? ''));
            if ($invoice !== '') {
                $lines[$invoice][] = $row['status'];
            }
        }
        $statuses = array_map([BillStatus::class, 'worst'], $lines);
        ksort($statuses, SORT_NATURAL);
        return $statuses;
    }

    /**
     * Parse formatted numeric string to float
     */
    private function parseMoney($val): float
    {
        return self::figure($val) ?? 0.0;
    }

    /** A printed figure such as "8,345.00" as a number, null for what is not one ("-", "Null", "") */
    private static function figure($val): ?float
    {
        $clean = str_replace([',', ' '], '', (string)$val);
        return is_numeric($clean) ? (float)$clean : null;
    }

    /**
     * Store number of every ASN invoice number: the ASN Dest Store No., else the store number the PDF prints
     *
     * @param array $results Rows returned by reconcile()
     * @return array invoice number => store number, '' when neither has one
     */
    public static function invoiceStores(array $results): array
    {
        $asn = [];
        $pdf = [];
        foreach ($results as $row) {
            $invoice = trim((string)($row['asn']['invoice_no'] ?? ''));
            if ($invoice === '') {
                continue;
            }
            $asnStore = trim((string)($row['asn']['dest_store_no'] ?? ''));
            $pdfStore = trim((string)($row['pdf']['store_code'] ?? ''));
            if (($asn[$invoice] ?? '') === '') {
                $asn[$invoice] = $asnStore;
            }
            if (($pdf[$invoice] ?? '') === '') {
                $pdf[$invoice] = $pdfStore;
            }
        }
        $stores = [];
        foreach ($asn as $invoice => $store) {
            $stores[$invoice] = $store !== '' ? $store : $pdf[$invoice];
        }
        return $stores;
    }

    /**
     * Extract 5-digit store number (e.g. 10114, or 020120 -> 20120) from Ship to Party or Address
     */
    private static function extractStoreCode(string $shipTo, string $address): string
    {
        if (preg_match('/\b0?(10\d{3}|20\d{3})\b/', $shipTo, $m)) {
            return $m[1];
        }
        if (preg_match('/\b0?(10\d{3}|20\d{3})\b/', $address, $m)) {
            return $m[1];
        }
        return '';
    }
}
