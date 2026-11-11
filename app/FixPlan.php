<?php

namespace App;

/**
 * Which invoice every PDF page of a Job belongs to, and what the fix button will change because of it.
 *
 * Built from the ASN rows and the PDF rows as they are now, it writes nothing. PdfFixer applies it, and the reconciler
 * judges the rows as applyTo() returns them and explains the plan before the click, so the status shown before the fix
 * is the one after it and the remark tells the problem and what the fix will do. Built again after a fix, it finds no
 * page to renumber and no footer to clear.
 */
class FixPlan
{
    public const CLEARED_FOOTER = [
        'total_shipped' => 'Null',
        'no_cartons' => 'Null',
        'promotion_discount' => 'Null',
        'total_amount' => 'Null',
        'discount_percent' => 'Null',
        'total_discount' => 'Null',
        'amt_after_discount' => 'Null',
        'amt_excl_vat' => 'Null',
        'vat_amount' => 'Null',
        'amt_incl_vat' => 'Null',
    ];

    /**
     * The problem of a bill whose pages print another bill's number, in the user's wording; the bill whose number they
     * print states the same sentence. %1$s those pages, %2$s the other pages of the bill
     */
    private const WRONG_NUMBER = '%1$s คาดว่าเลขบิลผิด เนื่องจากรวมกับ%2$s แล้วรายการถูกต้องตาม ASN';

    private const WRONG_NUMBER_ONLY = '%1$s คาดว่าเลขบิลผิด เนื่องจากรายการถูกต้องตาม ASN';

    /** The same for a bill that stays wrong after the fix, whose rows cannot be said to match the ASN. %3$s the bill */
    private const WRONG_NUMBER_OF = '%1$s คาดว่าเลขบิลผิด เนื่องจากรายการในหน้าเป็นของบิล %3$s';

    /** The problem of a bill whose numbers are right and whose total stands on a page that is not its last. %1$s those pages */
    private const EXTRA_TOTAL = '%1$s มียอดรวมท้ายบิล ทั้งที่ไม่ใช่หน้าสุดท้ายของบิล';

    /** Why a bill whose pages are right waits until the fix splits it out of its file. %1$s its pages */
    private const SPLIT_ONLY = 'ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่%1$s';

    /**
     * filename => [
     *     'invoices' => invoice => its page numbers, ascending
     *     'pages' => page number => [
     *         'invoice' => the invoice the page belongs to,
     *         'printed' => the number its rows carry (the first that differs from 'invoice'),
     *         'last' => whether it is the last page of that invoice in the file, the only one that keeps a footer,
     *         'renumber' => ids of the rows to renumber, 'clear_footer' => ids of the rows whose footer goes,
     *     ]
     *     'steps' => the renumber and clear_footer steps of the pages, as FixLog::record() takes them
     *     'split' => invoice => pages, the invoice PDFs the fix writes: every invoice once the file needs anything,
     *                except the one the file is already named after
     * ], files in job_items.id order
     *
     * @var array
     */
    private $files = [];

    /**
     * Files are walked in the order given and each consumes ASN quantity that later files can no longer claim
     *
     * @param array $asnRows rows with invoice_no, ean_upc, quantity
     * @param array $pdfItems rows in job_items.id order with id, filename, page_no, tax_invoice, barcode, units, amt_excl_vat
     */
    public function __construct(array $asnRows, array $pdfItems)
    {
        $asnRemaining = [];
        $rootInvoices = [];
        foreach ($asnRows as $a) {
            $rawInv = trim((string)$a['invoice_no']);
            $barClean = self::barcode((string)$a['ean_upc']);
            if ($rawInv === '' || $barClean === '') {
                continue;
            }
            $rootInvoices[self::root($rawInv)][$rawInv] = true;
            $asnRemaining[$rawInv][$barClean] = ($asnRemaining[$rawInv][$barClean] ?? 0) + (int)$a['quantity'];
        }
        foreach ($rootInvoices as $root => $invoices) {
            $names = array_map('strval', array_keys($invoices));
            usort($names, function ($x, $y) {
                return (int)substr(strrchr($x, '-') ?: '-0', 1) - (int)substr(strrchr($y, '-') ?: '-0', 1);
            });
            $rootInvoices[$root] = $names;
        }

        $byFile = [];
        foreach ($pdfItems as $item) {
            $byFile[$item['filename'] ?? ''][(int)($item['page_no'] ?? 1)][] = $item;
        }
        foreach ($byFile as $filename => $pages) {
            $this->files[$filename] = self::planFile($filename, $pages, $rootInvoices, $asnRemaining);
        }
    }

    /**
     * @return array filename => file plan, see $files
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * The PDF rows as the fix leaves them: each row carries the invoice its page belongs to, and a row on a page that
     * is not the last of its invoice has no footer. A row on a page the plan does not know stays as it is.
     *
     * @param array $pdfItems the rows the plan was built from, in any order
     */
    public function applyTo(array $pdfItems): array
    {
        foreach ($pdfItems as &$item) {
            $page = $this->files[$item['filename'] ?? '']['pages'][(int)($item['page_no'] ?? 1)] ?? null;
            if ($page === null) {
                continue;
            }
            [$renumber, $clearFooter] = self::changes($item, $page);
            if ($renumber) {
                $item['tax_invoice'] = $page['invoice'];
            }
            if ($clearFooter) {
                $item = array_replace($item, self::CLEARED_FOOTER);
            }
        }
        unset($item);
        return $pdfItems;
    }

    public function steps(): array
    {
        $steps = [];
        foreach ($this->files as $filename => $file) {
            $steps = array_merge($steps, $file['steps']);
            foreach ($file['split'] as $invoice => $pages) {
                $steps = array_merge($steps, self::splitSteps($filename, $invoice, $pages));
            }
        }
        return $steps;
    }

    public static function splitSteps($filename, $invoice, array $pages): array
    {
        $steps = [];
        foreach ($pages as $page) {
            $steps[] = ['kind' => FixLog::SPLIT, 'filename' => $filename, 'page' => $page, 'invoice' => $invoice];
        }
        return $steps;
    }

    /**
     * Per invoice, what is wrong with its pages, why a bill that will match waits for the fix, and what the fix will
     * do (the solution), '' when there is nothing
     *
     * A page printed with another bill's number makes one problem sentence, told by the bill it belongs to and by the
     * bill whose number it prints. That sentence explains the total on the bill's earlier page too, so a total is a
     * problem of its own only on a bill whose numbers are right. A bill whose pages are right still waits while the fix
     * has yet to split it out of a file that holds several bills. The solution names the steps FixLog::describe() will
     * tell after the fix, in its order.
     *
     * @param string[] $invoices
     * @param array $splitDone invoice key => true for the invoices already split out, from FixLog::splitDone()
     * @param array $wrongAfterFix invoice key => true for the bills that do not match the ASN even after the fix
     * @return array invoice => ['problem' => string, 'reason' => string, 'action' => string]
     */
    public function describe(array $invoices, array $splitDone, array $wrongAfterFix = []): array
    {
        $files = $this->files;
        ksort($files, SORT_STRING);
        $steps = [];
        $billPages = [];
        $printedWrong = [];
        $takenBy = [];
        $clearedFooterPages = [];
        $split = [];
        foreach ($files as $filename => $file) {
            $steps = array_merge($steps, $file['steps']);
            foreach ($file['split'] as $invoice => $pages) {
                $steps = array_merge($steps, self::splitSteps($filename, $invoice, $pages));
                $split[JobManager::invoiceKey((string)$invoice)][$filename] = $pages;
            }
            foreach ($file['invoices'] as $invoice => $pages) {
                $billPages[JobManager::invoiceKey((string)$invoice)][$filename] = $pages;
            }
            foreach ($file['pages'] as $pageNo => $page) {
                $owner = JobManager::invoiceKey((string)$page['invoice']);
                if (!empty($page['renumber'])) {
                    $printedWrong[$owner][$filename][] = $pageNo;
                    $takenBy[JobManager::invoiceKey((string)$page['printed'])][$owner] = (string)$page['invoice'];
                }
                if (!empty($page['clear_footer'])) {
                    $clearedFooterPages[$owner][$filename][] = $pageNo;
                }
            }
        }
        $done = FixLog::byInvoice($steps);

        $wrongNumber = function (string $owner, string $invoice) use ($billPages, $printedWrong, $wrongAfterFix): string {
            $named = count($billPages[$owner]) > 1;
            $rest = [];
            foreach ($billPages[$owner] as $filename => $pages) {
                $others = array_diff($pages, $printedWrong[$owner][$filename] ?? []);
                if (!empty($others)) {
                    $rest[$filename] = $others;
                }
            }
            $pattern = isset($wrongAfterFix[$owner]) ? self::WRONG_NUMBER_OF : (empty($rest) ? self::WRONG_NUMBER_ONLY : self::WRONG_NUMBER);
            return sprintf($pattern, FixLog::pagesText($printedWrong[$owner], $named), FixLog::pagesText($rest, $named), $invoice);
        };

        $texts = [];
        foreach ($invoices as $invoice) {
            $invoice = (string)$invoice;
            $key = JobManager::invoiceKey($invoice);
            $named = count($billPages[$key] ?? []) > 1;
            $owners = $takenBy[$key] ?? [];
            if (isset($printedWrong[$key])) {
                $owners = [$key => $invoice] + $owners;
            }
            $problems = [];
            foreach ($owners as $owner => $ownerInvoice) {
                $problems[] = $wrongNumber((string)$owner, $ownerInvoice);
            }
            if (!isset($printedWrong[$key]) && isset($clearedFooterPages[$key])) {
                $problems[] = sprintf(self::EXTRA_TOTAL, FixLog::pagesText($clearedFooterPages[$key], $named));
            }
            $problem = implode(FixLog::TOPICS, $problems);
            $splitOnly = isset($split[$key]) ? sprintf(self::SPLIT_ONLY, FixLog::pagesText($split[$key], count($split[$key]) > 1, 'หน้าที่')) : '';
            $texts[$invoice] = [
                'problem' => $problem,
                'reason' => $problem !== '' || isset($splitDone[$key]) ? $problem : $splitOnly,
                'action' => FixLog::sentences($done[$key] ?? [], $invoice, FixLog::PLANNED),
            ];
        }
        return $texts;
    }

    private static function planFile($filename, array $pages, array $rootInvoices, array &$asnRemaining): array
    {
        ksort($pages);
        $owners = [];
        $invoices = [];
        $openInvoice = null;
        foreach ($pages as $pNo => $items) {
            $rawPageInv = (string)($items[0]['tax_invoice'] ?? '');
            $candidates = $rootInvoices[self::root($rawPageInv)] ?? [];

            $invVotes = [];
            foreach ($items as $it) {
                $barClean = self::barcode((string)($it['barcode'] ?? ''));
                $units = (int)($it['units'] ?? 0);
                foreach (array_merge([$openInvoice, $rawPageInv], $candidates) as $candidate) {
                    if ($candidate !== null && in_array($candidate, $candidates, true)
                        && ($asnRemaining[$candidate][$barClean] ?? 0) >= $units) {
                        $asnRemaining[$candidate][$barClean] -= $units;
                        $invVotes[$candidate] = ($invVotes[$candidate] ?? 0) + 1;
                        break;
                    }
                }
            }

            if (!empty($invVotes)) {
                arsort($invVotes);
                $openInvoice = (string)array_key_first($invVotes);
            } else {
                $openInvoice = $rawPageInv;
            }
            $owners[$pNo] = $openInvoice;
            $invoices[$openInvoice][] = $pNo;
        }

        $planned = [];
        $steps = [];
        foreach ($pages as $pNo => $items) {
            $owner = (string)$owners[$pNo];
            $page = ['invoice' => $owner, 'printed' => $owner, 'last' => $pNo === max($invoices[$owner]), 'renumber' => [], 'clear_footer' => []];
            foreach ($items as $it) {
                [$renumber, $clearFooter] = self::changes($it, $page);
                if ($renumber) {
                    $page['printed'] = empty($page['renumber']) ? (string)($it['tax_invoice'] ?? null) : $page['printed'];
                    $page['renumber'][] = $it['id'] ?? null;
                }
                if ($clearFooter) {
                    $page['clear_footer'][] = $it['id'] ?? null;
                }
            }
            $planned[$pNo] = $page;

            $step = ['filename' => $filename, 'page' => $pNo, 'invoice' => $owner];
            if (!empty($page['renumber'])) {
                $steps[] = $step + ['kind' => FixLog::RENUMBER, 'from' => $page['printed']];
            }
            if (!empty($page['clear_footer'])) {
                $steps[] = $step + ['kind' => FixLog::CLEAR_FOOTER];
            }
        }

        $split = [];
        if (!empty($steps) || count($invoices) > 1) {
            foreach ($invoices as $invoice => $pList) {
                if (strcasecmp($invoice . '.pdf', basename((string)$filename)) !== 0) {
                    $split[$invoice] = $pList;
                }
            }
        }

        return ['invoices' => $invoices, 'pages' => $planned, 'steps' => $steps, 'split' => $split];
    }

    private static function changes(array $item, array $page): array
    {
        $excl = $item['amt_excl_vat'] ?? null;
        return [(string)($item['tax_invoice'] ?? '') !== (string)$page['invoice'], !$page['last'] && $excl !== 'Null' && $excl !== '-'];
    }

    private static function root(string $invoice): string
    {
        $parts = explode('-', $invoice);
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $parts[0]));
    }

    private static function barcode(string $raw): string
    {
        $bar = trim($raw);
        return ctype_digit($bar) ? $bar : preg_replace('/[^0-9]/', '', $bar);
    }
}
