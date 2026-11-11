<?php
namespace App;

use Smalot\PdfParser\Parser;

class PdfExtractor
{
    /** @var Parser */
    private $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * ทำความสะอาดและสกัดกลุ่มตัวเลขทางการเงิน
     */
    public static function cleanNumericLines(string $rawSection): array
    {
        $rawLines = explode("\n", strip_tags($rawSection));
        $combinedText = '';

        foreach ($rawLines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/[a-zA-Z]/', $line)) {
                continue;
            }
            $combinedText .= ' ' . $line;
        }

        $combinedText = preg_replace('/\s*,\s*/', ',', $combinedText);
        $combinedText = preg_replace('/\s*\.\s*/', '.', $combinedText);

        preg_match_all('/-?[\d,]+\.\d{2}/', $combinedText, $matches);
        if (!empty($matches[0])) {
            return $matches[0];
        }

        preg_match_all('/-?[\d,]+(?:\.\d+)?/', $combinedText, $matchesFallback);
        return $matchesFallback[0] ?? [];
    }

    /**
     * Distinct invoice roots (number without the -N suffix) printed in a PDF
     *
     * @param string $filePath
     * @return array
     */
    public function invoiceRoots(string $filePath): array
    {
        $roots = [];
        foreach ($this->parser->parseFile($filePath)->getPages() as $page) {
            if (preg_match('/(?:Tax Invoice(?:\s*No)?|CREDIT NOTE No)\s*:\s*([0-9]{7,12})/i', $page->getText(), $m)) {
                $roots[$m[1]] = true;
            }
        }
        return array_keys($roots);
    }

    /**
     * สกัดข้อมูลแบบละเอียดระดับรายการสินค้า (1 แถวต่อ 1 สินค้า) แยกตามหน้า
     */
    public function extractDetailedRows(string $filePath): array
    {
        $fileName = basename($filePath);
        $pdf = $this->parser->parseFile($filePath);
        $pages = $pdf->getPages();

        $rows = [];
        $prevTaxInvoice = '-';
        $prevHeader = null;

        foreach ($pages as $pageIndex => $page) {
            $pageNo = $pageIndex + 1;
            $text = $page->getText();

            // 1. Header Information
            // Tax Invoice No. or Credit Note No. (must start with digits, avoiding Z090 term codes on blank headers)
            $taxInvoice = '-';
            if (preg_match('/(?:Tax Invoice(?:\s*No)?|CREDIT NOTE No)\s*:\s*([0-9]{7,12}(?:[\s\r\n]*-[\s\r\n]*[0-9]+)?)/i', $text, $mInv)) {
                $taxInvoice = preg_replace('/\s+/', '', $mInv[1]);
            } elseif ($prevTaxInvoice !== '-') {
                $taxInvoice = $prevTaxInvoice;
            }

            // Date
            preg_match('/Date\s*:\s*([0-9\.\-]+)/i', $text, $mDate);
            $date = $mDate[1] ?? '-';

            // Address (Sold to Party)
            $address = '-';
            if (preg_match('/Sold to Party\s+Customer\s*Acc(?:nt|\.)\s*No\.\s*:\s*[0-9]+\s+(.*?)(?:Tax\s*Id\s*:)/is', $text, $mAddr)) {
                $address = trim(preg_replace('/\s+/', ' ', $mAddr[1]));
            } elseif (preg_match('/Sold to Party\s+(.*?)(?:Tax\s*Id\s*:)/is', $text, $mAddr2)) {
                $address = trim(preg_replace('/\s+/', ' ', $mAddr2[1]));
            }

            // Tax ID
            preg_match('/Tax Id:\s*([0-9]{13})/i', $text, $mTax);
            $taxId = $mTax[1] ?? '-';

            // Branch code
            preg_match('/Branch code:\s*([0-9]+)/i', $text, $mBranch);
            $branchCode = $mBranch[1] ?? '-';

            // A (Term Code) and B (Term Text)
            $termA = '-';
            $termB = '-';
            if (preg_match('/(?:Tax Invoice[^\n\r]+\s+)([A-Z0-9]{4})\s+([^\n\r]+?)(?:Ship to Party:)/s', $text, $mAB)) {
                $termA = $mAB[1] ?? '-';
                $termB = trim($mAB[2] ?? '-');
            } elseif (preg_match('/([A-Z0-9]{4})\s+(CutOff[^\n\r]+)/i', $text, $mAB2)) {
                $termA = $mAB2[1] ?? '-';
                $termB = trim($mAB2[2] ?? '-');
            }

            // Ship to Party
            $shipToParty = '-';
            if (preg_match('/Ship to Party:\s*(.*?)\s*(?:PRODUCT\/SZ|PRODUCT\/SIZE|Promotion Discount|Total Amount|690 UOB Plaza)/is', $text, $mShip)) {
                $shipToParty = trim(preg_replace('/\s+/', ' ', $mShip[1]));
            }

            if ($prevHeader !== null && $taxInvoice === $prevTaxInvoice) {
                if ($date === '-') $date = $prevHeader['date'];
                if ($address === '-') $address = $prevHeader['address'];
                if ($taxId === '-') $taxId = $prevHeader['tax_id'];
                if ($branchCode === '-') $branchCode = $prevHeader['branch_code'];
                if ($termA === '-') $termA = $prevHeader['term_a'];
                if ($termB === '-') $termB = $prevHeader['term_b'];
                if ($shipToParty === '-') $shipToParty = $prevHeader['ship_to_party'];
            }
            $prevHeader = [
                'date' => $date,
                'address' => $address,
                'tax_id' => $taxId,
                'branch_code' => $branchCode,
                'term_a' => $termA,
                'term_b' => $termB,
                'ship_to_party' => $shipToParty
            ];

            // 2. Summary Block
            $hasSummary = (stripos($text, 'TOTAL SHIPPED') !== false || stripos($text, 'Promotion Discount') !== false || stripos($text, 'Total Amt Include VAT') !== false);

            $totalShipped = 'Null';
            $noCartons = 'Null';
            $promoDiscount = 'Null';
            $totalAmount = 'Null';
            $discountPct = 'Null';
            $totalDiscount = 'Null';
            $amtAfterDiscount = 'Null';
            $amtExclVat = 'Null';
            $vatAmount = 'Null';
            $amtInclVat = 'Null';

            if ($hasSummary) {
                // TOTAL SHIPPED (UNITS)
                if (preg_match('/TOTAL SHIPPED \(UNITS\)(?::)?\s*(?:NO CARTONS \(CTN\))?\s*(\d+(?:\.\d+)?)/i', $text, $mShipUnits)) {
                    $totalShipped = $mShipUnits[1];
                }

                // Discount Percent
                if (preg_match('/Total Discount\s*\(\s*(\d+)%\s*\)/i', $text, $mDiscPct)) {
                    $discountPct = $mDiscPct[1] . '%';
                }

                // Numeric Summary lines
                $summarySection = '';
                if (preg_match('/Total Amt Include VAT(.*?)(?:LEVI STRAUSS|Computer printed|$)/s', $text, $mSum)) {
                    $summarySection = $mSum[1];
                } elseif (preg_match('/(?:Promotion Discount|Total Amount)(.*?)(?:LEVI STRAUSS|Computer printed|$)/s', $text, $mSum2)) {
                    $summarySection = $mSum2[1];
                }
                // The Promotion Discount shares the line of the shipped units ("NO CARTONS (CTN)  13  8,345.00"), a line
                // cleanNumericLines() would skip for its letters
                $summarySection = preg_replace('/TOTAL SHIPPED \(UNITS\):?\s*(?:NO CARTONS \(CTN\))?\s*\d+(?:\.\d+)?(?![\d,.])/i', '', $summarySection);

                $numericValues = self::cleanNumericLines($summarySection);

                if (count($numericValues) < 6 && preg_match('/^(.*?)(?:Date\s*:)/s', $text, $mBeforeDate)) {
                    if (preg_match_all('/-?[\d,]+\.\d{2}/', $mBeforeDate[1], $mPreNums) && count($mPreNums[0]) >= 6) {
                        $last6 = array_slice($mPreNums[0], -6);
                        if (count($numericValues) >= 1) {
                            $numericValues = array_merge([$numericValues[0]], $last6);
                        } else {
                            $numericValues = $last6;
                        }
                    }
                }

                if (count($numericValues) >= 7) {
                    $promoDiscount     = $numericValues[0];
                    $totalAmount       = $numericValues[1];
                    $totalDiscount     = $numericValues[2];
                    $amtAfterDiscount  = $numericValues[3];
                    $amtExclVat        = $numericValues[4];
                    $vatAmount         = $numericValues[5];
                    $amtInclVat        = $numericValues[6];
                } elseif (count($numericValues) === 6) {
                    $promoDiscount     = '-';
                    $totalAmount       = $numericValues[0];
                    $totalDiscount     = $numericValues[1];
                    $amtAfterDiscount  = $numericValues[2];
                    $amtExclVat        = $numericValues[3];
                    $vatAmount         = $numericValues[4];
                    $amtInclVat        = $numericValues[5];
                }
            }

            // 3. Product Items in this page
            $lines = explode("\n", $text);
            $isProd = false;
            $items = [];
            $currentItem = null;
            $pendingProdCode = null;

            foreach ($lines as $line) {
                $trim = trim($line);
                if (stripos($trim, 'PRODUCT/SZ') !== false || stripos($trim, 'PRODUCT/SIZE') !== false) {
                    $isProd = true;
                    continue;
                }
                if ($isProd && (
                    stripos($trim, 'TOTAL SHIPPED') !== false ||
                    stripos($trim, 'Promotion Discount') !== false ||
                    stripos($trim, 'Sold to Party') !== false ||
                    stripos($trim, 'CREDIT NOTE') !== false ||
                    stripos($trim, 'Tax Invoice') !== false ||
                    stripos($trim, 'VAT') !== false ||
                    stripos($trim, 'Total Amt') !== false ||
                    stripos($trim, 'AUTHORIZED SIGNATURE') !== false ||
                    (preg_match('/^-?[\d,]+\.\d{2}$/', $trim) && $currentItem && $currentItem['amount'] !== '0.00')
                )) {
                    $isProd = false;
                    if ($currentItem) {
                        $items[] = $currentItem;
                        $currentItem = null;
                    }
                    $pendingProdCode = null;
                }

                if ($isProd) {
                    // Case A: Product code and barcode on the same line
                    if (preg_match('/^([A-Z0-9\-]+(?:[\/A-Z0-9\-]+|\s+[A-Z0-9]+\s+[A-Z0-9\-]+)?)\s+(\d{13})(.*)$/i', $trim, $m)) {
                        if ($currentItem) {
                            $items[] = $currentItem;
                        }
                        $rawDesc = trim($m[3]);
                        $units = '1';
                        $price = '0.00';
                        $amt = '0.00';
                        if (preg_match('/(\d+)\s+([\d,]+(?:\.\d{2})?)\s+([\d,]+(?:\.\d{2})?)\s*$/', $rawDesc, $mNum)) {
                            $units = $mNum[1];
                            $price = $mNum[2];
                            $amt = $mNum[3];
                            $rawDesc = trim(substr($rawDesc, 0, -strlen($mNum[0])));
                        }
                        $currentItem = [
                            'prod_sz' => trim($m[1]),
                            'barcode' => trim($m[2]),
                            'desc' => $rawDesc,
                            'units' => $units,
                            'unit_price' => $price,
                            'amount' => $amt
                        ];
                        $pendingProdCode = null;
                    }
                    // Case B: Standalone product code on its own line (e.g. 00505-15503132)
                    elseif (preg_match('/^([A-Z0-9]{5,}\-[A-Z0-9]{4,})$/i', $trim, $mCode)) {
                        if ($currentItem) {
                            $items[] = $currentItem;
                            $currentItem = null;
                        }
                        $pendingProdCode = trim($mCode[1]);
                    }
                    // Case C: Barcode on its own line following a pending product code
                    elseif ($pendingProdCode && preg_match('/^(\d{13})\s*(.*)$/', $trim, $mBar)) {
                        if ($currentItem) {
                            $items[] = $currentItem;
                        }
                        $rawDesc = trim($mBar[2]);
                        $units = '1';
                        $price = '0.00';
                        $amt = '0.00';
                        if (preg_match('/(\d+)\s+([\d,]+(?:\.\d{2})?)\s+([\d,]+(?:\.\d{2})?)\s*$/', $rawDesc, $mNum)) {
                            $units = $mNum[1];
                            $price = $mNum[2];
                            $amt = $mNum[3];
                            $rawDesc = trim(substr($rawDesc, 0, -strlen($mNum[0])));
                        }
                        $currentItem = [
                            'prod_sz' => $pendingProdCode,
                            'barcode' => trim($mBar[1]),
                            'desc' => $rawDesc,
                            'units' => $units,
                            'unit_price' => $price,
                            'amount' => $amt
                        ];
                        $pendingProdCode = null;
                    }
                    elseif ($currentItem) {
                        $cleanLine = preg_replace('/(?:Date:|Tax Invoice|Z\d+|CutOff|FREIGHT|BARCODE).*$/i', '', $trim);
                        $cleanLine = trim($cleanLine);

                        if (preg_match('/^1(\d{1,2},\d{3}(?:\.\d{2})?)\s+(\d{1,2},\d{3}(?:\.\d{2})?)/', $cleanLine, $mGlued) && $mGlued[1] === $mGlued[2]) {
                            $currentItem['units'] = '1';
                            $currentItem['unit_price'] = $mGlued[1];
                            $currentItem['amount'] = $mGlued[2];
                            $items[] = $currentItem;
                            $currentItem = null;
                        } elseif (preg_match('/(\d+)\s+([\d,]+(?:\.\d{2})?)\s+([\d,]+(?:\.\d{2})?)\s*$/', $cleanLine, $mNum)) {
                            $currentItem['units'] = $mNum[1];
                            $currentItem['unit_price'] = $mNum[2];
                            $currentItem['amount'] = $mNum[3];
                            $remainingDesc = trim(substr($cleanLine, 0, -strlen($mNum[0])));
                            if ($remainingDesc) {
                                $currentItem['desc'] .= ' ' . $remainingDesc;
                            }
                            $items[] = $currentItem;
                            $currentItem = null;
                        } elseif ($cleanLine !== '' && preg_match('/(\d+)\s+([\d,]+(?:\.\d{2})?)\s+([\d,]+(?:\.\d{2})?)\s*$/', trim($currentItem['desc'] . ' ' . $cleanLine), $mCombined)) {
                            $combined = trim($currentItem['desc'] . ' ' . $cleanLine);
                            $currentItem['units'] = $mCombined[1];
                            $currentItem['unit_price'] = $mCombined[2];
                            $currentItem['amount'] = $mCombined[3];
                            $currentItem['desc'] = trim(substr($combined, 0, -strlen($mCombined[0])));
                            $items[] = $currentItem;
                            $currentItem = null;
                        } else {
                            if ($cleanLine !== '') {
                                $currentItem['desc'] .= ' ' . $cleanLine;
                            }
                        }
                    }
                }
            }

            if ($currentItem) {
                if (preg_match('/(\d+)\s+([\d,]+(?:\.\d{2})?)\s+([\d,]+(?:\.\d{2})?)/', $currentItem['desc'], $mNum)) {
                    $currentItem['units'] = $mNum[1];
                    $currentItem['unit_price'] = $mNum[2];
                    $currentItem['amount'] = $mNum[3];
                    $currentItem['desc'] = trim(str_replace($mNum[0], '', $currentItem['desc']));
                }
                $items[] = $currentItem;
            }

            $prevTaxInvoice = $taxInvoice;

            // สร้างแถวข้อมูลสำหรับแต่ละรายการสินค้าในหน้านี้
            foreach ($items as $item) {
                $rows[] = [
                    'filename' => $fileName,
                    'page_no' => $pageNo,
                    'tax_invoice' => $taxInvoice,
                    'date' => $date,
                    'address' => $address,
                    'tax_id' => $taxId,
                    'branch_code' => $branchCode,
                    'ship_to_party' => $shipToParty,
                    'term_a' => $termA,
                    'term_b' => $termB,
                    'product_sz' => $item['prod_sz'],
                    'barcode' => $item['barcode'],
                    'description' => trim(preg_replace('/\s+/', ' ', $item['desc'])),
                    'units' => $item['units'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                    'total_shipped' => $totalShipped,
                    'no_cartons' => $noCartons,
                    'promotion_discount' => $promoDiscount,
                    'total_amount' => $totalAmount,
                    'discount_percent' => $discountPct,
                    'total_discount' => $totalDiscount,
                    'amt_after_discount' => $amtAfterDiscount,
                    'amt_excl_vat' => $amtExclVat,
                    'vat_amount' => $vatAmount,
                    'amt_incl_vat' => $amtInclVat
                ];
            }
        }

        return $rows;
    }

    /**
     * สกัดข้อมูลระดับใบลดหนี้ (Credit Note Document Level) สำหรับหน้า read_cn_error01
     * รองรับทั้ง Attachment Sheets (เช่น 0093260445), Variance / Refund และ Standard CN
     */
    public function extractCnDocuments(string $filePath): array
    {
        $pageTexts = [];
        foreach ($this->parser->parseFile($filePath)->getPages() as $pIdx => $page) {
            $pageTexts[$pIdx] = $page->getText();
        }
        return array_column(self::cnDocumentsOfPages(basename($filePath), $pageTexts), 'document');
    }

    /**
     * The credit note documents of a file's page texts (page key => getText() of the page), each as
     * ['document' => what extractCnDocuments returns for it, 'page_keys' => the keys of its pages]; [] when no page
     * names a credit note. CreditNoteReader reads the same parse through here.
     */
    public static function cnDocumentsOfPages(string $fileName, array $pageTexts): array
    {
        $fullText = implode("\n", $pageTexts);
        if (stripos($fullText, 'Credit Note') === false && stripos($fullText, 'Attachment to Original Credit') === false) {
            return [];
        }

        $docGroups = [];
        foreach ($pageTexts as $pIdx => $pText) {
            $pDocNo = '-';
            if (preg_match('/(?:Credit\s*Note(?:\s*No)?|Attachment to Original Credit\s*Note No|CREDIT NOTE No)\s*:\s*([^\s\n\r]+)/i', $pText, $m)) {
                $pDocNo = trim(str_replace(["\n", "\r", " "], '', $m[1]));
            }

            if (!isset($docGroups[$pDocNo])) {
                $docGroups[$pDocNo] = [
                    'pages' => [],
                    'page_keys' => [],
                    'text' => ''
                ];
            }
            $docGroups[$pDocNo]['pages'][] = $pIdx + 1;
            $docGroups[$pDocNo]['page_keys'][] = $pIdx;
            $docGroups[$pDocNo]['text'] .= "\n" . $pText;
        }

        $results = [];
        foreach ($docGroups as $docNo => $group) {
            $text = $group['text'];
            $pageRange = (count($group['pages']) === 1) ? (string)$group['pages'][0] : ($group['pages'][0] . '-' . end($group['pages']));

            // Date
            $docDate = '-';
            if (preg_match('/(?:Date|Credit Note Date)\s*:\s*([0-9\.\-]+)/i', $text, $mDate)) {
                $docDate = $mDate[1];
            }

            // Customer: the store line (5029-CENTRAL PINKLAO 10110, 5048-ROBINSON BOWIN 20186, 5036-CDS ..., 5052-RBS ...).
            // On a standard credit note smalot joins it with the seller's address column (" Khlong Toei,").
            $customer = '-';
            if (preg_match('/^\s*([0-9]{4}-[A-Z][^\n\r]*?)(?:\s+Khlong Toei,)?\s*$/m', $text, $mCust)) {
                $customer = trim($mCust[1]);
            }

            // Format Type & Amounts
            $isAttachment = (stripos($text, 'Attachment to Original Credit') !== false);
            $hasRefundBlock = (stripos($text, 'Amount Refunded') !== false || stripos($text, 'Difference amount') !== false);

            $origAmount = '-';
            $corrAmount = '-';
            $diffAmount = '-';
            $vatAmount = '-';
            $amount = '-';
            $formatType = 'Standard CN';

            if ($isAttachment) {
                $formatType = 'Attachment Sheet';
                $pos = stripos($text, 'Total amount per the original');
                if ($pos !== false) {
                    $prefix = substr($text, 0, $pos);
                    preg_match_all('/[\d,]+\.\d{2}/', $prefix, $allNums);
                    if (!empty($allNums[0])) {
                        $last5 = array_slice($allNums[0], -5);
                        if (count($last5) >= 5) {
                            $origAmount = $last5[0];
                            $corrAmount = $last5[1];
                            $diffAmount = $last5[2];
                            $vatAmount  = $last5[3];
                            $amount     = $last5[4]; // Amount Refunded
                        }
                    }
                }
            } elseif ($hasRefundBlock) {
                $formatType = 'Variance / Refund';
                $pos = stripos($text, 'Amount Refunded');
                if ($pos !== false) {
                    $suffix = substr($text, $pos);
                    $window = substr($suffix, 0, 250);
                    preg_match_all('/[\d,]+\.\d{2}/', $window, $matches);
                    if (!empty($matches[0])) {
                        $nums = $matches[0];
                        if (count($nums) >= 5) {
                            $origAmount = $nums[0];
                            $corrAmount = $nums[1];
                            $amount     = end($nums);
                            $vatAmount  = $nums[count($nums) - 2];
                            $diffAmount = $nums[count($nums) - 3];
                        } else {
                            $amount = end($nums);
                        }
                    }
                }

                if ($amount === '-' && preg_match('/(?:Total\s*Amt\s*Include\s*VAT)[^\d]*([\d,]+\.\d{2})/i', $text, $mTot)) {
                    $amount = $mTot[1];
                }
            } else {
                $formatType = 'Standard CN';
                $summary = self::longestAmountRow($text);
                if (preg_match('/(?:Total\s*Amt\s*Include\s*VAT)[^\d]*([\d,]+\.\d{2})/i', $text, $mTot)) {
                    $amount = $mTot[1];
                } elseif ($summary) {
                    $amount = end($summary);
                }

                if (preg_match('/(?:VAT\s*\(7%\)|VAT)[^\d]*([\d,]+\.\d{2})/i', $text, $mVat)) {
                    $vatAmount = $mVat[1];
                } elseif ($summary) {
                    $vatAmount = $summary[count($summary) - 2];
                }
            }

            $finalDocNo = ($docNo !== '-' ? $docNo : (preg_match('/([0-9]{8})/i', $fileName, $mF) ? $mF[1] : '-'));

            $results[] = [
                'document' => [
                    'doc_no' => $finalDocNo,
                    'doc_date' => $docDate,
                    'customer' => $customer,
                    'format_type' => $formatType,
                    'filename' => $fileName,
                    'pages' => $pageRange,
                    'orig_amount' => $origAmount,
                    'corr_amount' => $corrAmount,
                    'diff_amount' => $diffAmount,
                    'vat_amount' => $vatAmount,
                    'amount' => $amount
                ],
                'page_keys' => $group['page_keys'],
            ];
        }

        return $results;
    }

    /**
     * smalot prints the summary of a standard credit note as one row of amounts before their labels, ending with
     * Total Amt Excluding VAT, VAT (7%) and Total Amt Include VAT. The longest row of three or more amounts that
     * only white space separates is that summary; a date such as 09.04.2026 is not an amount.
     */
    private static function longestAmountRow(string $text): array
    {
        $amount = '-?[\d,]+\.\d{2}(?![\d.])';
        preg_match_all('/(?<![\d.,])' . $amount . '(?:\s+' . $amount . ')*/', $text, $m);
        $longest = [];
        foreach ($m[0] as $row) {
            $amounts = preg_split('/\s+/', trim($row));
            if (count($amounts) > count($longest)) {
                $longest = $amounts;
            }
        }
        return count($longest) >= 3 ? $longest : [];
    }
}

