<?php

namespace App;

use PDO;

/**
 * reconcileJob(), and getInvoiceDashboard() on a compared Job, write the comparison counts on the Job row through
 * AsnReconciler::reconcile.
 */
class DashboardBuilder
{
    /** @var PDO */
    private $db;

    /** @var JobRepository */
    private $jobs;

    /** @var PdfItemStore */
    private $items;

    /** @var AsnStore */
    private $asn;

    /** @var BillHistory */
    private $history;

    public function __construct(PDO $db, JobRepository $jobs, PdfItemStore $items, AsnStore $asn, BillHistory $history)
    {
        $this->db = $db;
        $this->jobs = $jobs;
        $this->items = $items;
        $this->asn = $asn;
        $this->history = $history;
    }

    public static function invoiceKey(string $invoice): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $invoice));
    }

    private static function pagesByInvoice(array $items): array
    {
        $pages = [];
        foreach ($items as $item) {
            $key = self::invoiceKey((string)($item['tax_invoice'] ?? ''));
            if ($key !== '') {
                $pages[$key][(string)($item['filename'] ?? '')][(int)($item['page_no'] ?? 1)] = true;
            }
        }
        foreach ($pages as &$byFile) {
            foreach ($byFile as &$set) {
                $set = array_keys($set);
                sort($set);
            }
            unset($set);
        }
        unset($byFile);
        return $pages;
    }

    public function reconcileJob(int $jobId, PrintManager $printer): array
    {
        $items = $this->items->getJobItems($jobId);
        $result = (new AsnReconciler($this->db))->reconcile($jobId, $items, $this->jobs->getJob($jobId));

        $pages = self::pagesByInvoice($items);
        $fixed = $this->fixedInvoices($jobId, AsnReconciler::invoiceStatuses($result['results']), $pages);
        $printed = $printer->printedInvoices($jobId);
        $history = $this->history->forJob($jobId);
        foreach ($result['results'] as &$row) {
            $invoice = trim((string)($row['asn']['invoice_no'] ?? ''));
            $row['printed_round'] = $printed[$invoice] ?? null;
            $row['fixed'] = isset($fixed[$invoice]);
            $row['fix_note'] = $fixed[$invoice] ?? '';
            $row['source_files'] = self::sourceFiles($pages, $invoice);
            $was = BillHistory::was($history[$invoice] ?? []);
            if (!empty($was)) {
                $row['was'] = $was;
            }
        }
        unset($row);

        return $result;
    }

    /**
     * The bills the fix button worked on that wait for nothing now, with what it did
     */
    private function fixedInvoices(int $jobId, array $statuses, array $pages): array
    {
        $invoices = array_filter($this->items->fixedInvoiceNumbers($jobId), function ($invoice) use ($statuses) {
            return isset($statuses[$invoice]) && !in_array($statuses[$invoice], [BillStatus::PENDING_FIX, BillStatus::PENDING_ASN], true);
        });
        return (new FixLog($this->db))->describe($jobId, $invoices, $pages);
    }

    /**
     * Every ASN bill of the Job with its BillHistory category now and its problem ('' for a bill that matches), from
     * one reconcile
     *
     * @return array invoice number => ['category' => string, 'detail' => string]
     */
    public function billCategories(int $jobId): array
    {
        $results = (new AsnReconciler($this->db))->reconcile($jobId, $this->items->getJobItems($jobId), $this->jobs->getJob($jobId))['results'];
        $fixed = array_flip(array_map('strval', $this->items->fixedInvoiceNumbers($jobId)));
        $reasons = [];
        foreach ($results as $line) {
            $reasons[trim((string)($line['asn']['invoice_no'] ?? ''))][$line['status']][trim((string)$line['reason'])] = true;
        }
        $bills = [];
        foreach (AsnReconciler::invoiceStatuses($results) as $invoice => $status) {
            $own = $status === BillStatus::MATCH ? [] : array_keys($reasons[(string)$invoice][$status]);
            $bills[$invoice] = [
                'category' => BillHistory::category($status, isset($fixed[(string)$invoice])),
                'detail' => implode(FixLog::TOPICS, array_filter($own, 'strlen')),
            ];
        }
        return $bills;
    }

    private static function sourceFiles(array $pages, string $invoice): array
    {
        return array_map('strval', array_keys($pages[self::invoiceKey($invoice)] ?? []));
    }

    /**
     * Counts of invoice numbers (not rows) for the Job dashboard
     *
     * complete: right from the start. not_fixed: still waiting for fix. fixed: the fix button took it out of
     * waiting and it does not wait for the ASN. needs_fix: not_fixed + fixed, which does not shrink when the fix is
     * pressed. error: wrong against the ASN, error_after_fix of those were fixed before and are still wrong.
     * pending_asn, only with VBRP rows: waiting for the ASN, fixed or not.
     */
    public function getInvoiceDashboard(int $jobId): array
    {
        $job = $this->jobs->getJob($jobId);
        $asnInvoices = $this->asn->invoiceCount($jobId);

        if (!$job || $asnInvoices === 0 || empty($job['asn_reconciled'])) {
            return [
                'compared' => false, 'total' => $asnInvoices, 'complete' => null, 'needs_fix' => null,
                'not_fixed' => null, 'fixed' => null, 'error' => null, 'error_after_fix' => null,
                'invoices' => [],
            ];
        }

        $items = $this->items->getJobItems($jobId);
        $reconciled = (new AsnReconciler($this->db))->reconcile($jobId, $items, $job);
        $statuses = AsnReconciler::invoiceStatuses($reconciled['results']);
        $pages = self::pagesByInvoice($items);
        $fixed = $this->fixedInvoices($jobId, $statuses, $pages);

        $count = ['complete' => 0, 'not_fixed' => 0, 'fixed' => 0, 'error' => 0, 'error_after_fix' => 0, 'pending_asn' => 0];
        foreach ($statuses as $invoice => $status) {
            $wasFixed = isset($fixed[$invoice]);
            if ($status === BillStatus::PENDING_FIX) {
                $count['not_fixed']++;
            } elseif ($status === BillStatus::PENDING_ASN) {
                $count['pending_asn']++;
            } elseif ($wasFixed) {
                $count['fixed']++;
            } elseif ($status === BillStatus::MATCH) {
                $count['complete']++;
            }
            if ($status === BillStatus::MISMATCH) {
                $count['error']++;
                $count['error_after_fix'] += $wasFixed ? 1 : 0;
            }
        }

        // With VBRP rows every bill row gets its three-source columns, and the payload the bills each source got wrong
        $figures = isset($reconciled['summary']['vbrp']) ? VbrpFigures::forJob($this->db, $jobId) : null;
        $dashboard = [
            'compared' => true,
            'total' => count($statuses),
            'complete' => $count['complete'],
            'needs_fix' => $count['not_fixed'] + $count['fixed'],
            'not_fixed' => $count['not_fixed'],
            'fixed' => $count['fixed'],
            'error' => $count['error'],
            'error_after_fix' => $count['error_after_fix'],
            'invoices' => $this->buildInvoiceRows($reconciled['results'], $items, $statuses, $fixed, $pages, $figures, $this->history->forJob($jobId)),
        ];
        if ($figures !== null) {
            $dashboard['pending_asn'] = $count['pending_asn'];
            $dashboard['verdict_counts'] = self::verdictCounts($dashboard['invoices']);
        }
        return $dashboard;
    }

    /**
     * Bills per source and verdict, null when VBRP covers no bill
     */
    private static function verdictCounts(array $rows): ?array
    {
        $counts = array_fill_keys(array_keys(SourceVerdict::SOURCES), [SourceVerdict::OK => 0, SourceVerdict::WRONG => 0, SourceVerdict::NA => 0]);
        $covered = false;
        foreach ($rows as $row) {
            $covered = $covered || $row['vbrp'] !== null;
            foreach ($row['verdict'] as $source => $verdict) {
                $counts[$source][$verdict]++;
            }
        }
        return $covered ? $counts : null;
    }

    /**
     * The three-source columns of a bill: each source's worst verdict over the lines (wrong, then ok, then na; all na
     * for a bill VBRP does not cover), the bill's VBRP figures, and the notes of its lines, each once. As with problems,
     * a note only one of several lines holds says its barcode, and three are shown, then the count of the rest.
     */
    private static function sourceColumns(array $lines, ?array $bill): array
    {
        $rank = [SourceVerdict::NA => 0, SourceVerdict::OK => 1, SourceVerdict::WRONG => 2];
        $verdict = array_fill_keys(array_keys(SourceVerdict::SOURCES), SourceVerdict::NA);
        $holders = []; // note => barcodes of the lines that hold it
        foreach ($lines as $line) {
            foreach ($line['sources']['overall'] ?? [] as $source => $lineVerdict) {
                if ($rank[$lineVerdict] > $rank[$verdict[$source]]) {
                    $verdict[$source] = $lineVerdict;
                }
            }
            foreach ($line['sources']['notes'] ?? [] as $note) {
                $holders[$note][] = trim((string)($line['asn']['ean_upc'] ?? ''));
            }
        }
        $notes = [];
        foreach ($holders as $note => $barcodes) {
            $ownLine = count($barcodes) === 1 && count($lines) > 1 && $barcodes[0] !== '';
            $notes[] = ($ownLine ? 'บาร์โค้ด ' . $barcodes[0] . ': ' : '') . $note;
        }
        if (count($notes) > 3) {
            $notes = array_slice($notes, 0, 3);
            $notes[2] .= ' และอีก ' . (count($holders) - 3) . ' ประเด็น';
        }
        return [
            'verdict' => $verdict,
            'vbrp' => $bill === null ? null : ['cost' => $bill['cost'], 'vat' => $bill['vat'], 'lines' => $bill['lines']],
            'notes' => $notes,
        ];
    }

    /**
     * @param array $history invoice number => its BillHistory rows, from BillHistory::forJob()
     */
    private function buildInvoiceRows(array $results, array $items, array $statuses, array $fixed, array $pages, ?VbrpFigures $figures, array $history): array
    {
        $pdf = [];
        foreach ($items as $item) {
            $key = self::invoiceKey((string)($item['tax_invoice'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!isset($pdf[$key])) {
                $pdf[$key] = ['units' => 0, 'branch' => null];
            }
            $pdf[$key]['units'] += (int)($item['units'] ?? 0);
            if ($pdf[$key]['branch'] === null && trim((string)($item['branch_code'] ?? '')) !== '') {
                $pdf[$key]['branch'] = trim((string)$item['branch_code']);
            }
        }

        $lines = [];
        foreach ($results as $line) {
            $lines[trim((string)($line['asn']['invoice_no'] ?? ''))][] = $line;
        }
        $stores = AsnReconciler::invoiceStores($results);

        $rows = [];
        foreach ($statuses as $invoice => $status) {
            $invoice = (string)$invoice;
            $wasFixed = isset($fixed[$invoice]);
            $found = $pdf[self::invoiceKey($invoice)] ?? null;
            $filePages = $pages[self::invoiceKey($invoice)] ?? [];

            $reasons = [];
            $reasonCount = [];
            $reasonBarcode = [];
            $actionOf = [];
            $culprit = '';
            if ($status !== BillStatus::MATCH) {
                foreach ($lines[$invoice] as $line) {
                    if ($line['status'] !== $status) {
                        continue;
                    }
                    if ($culprit === '') {
                        $culprit = (string)($line['culprit'] ?? '');
                    }
                    $reason = trim((string)($line['reason'] ?? ''));
                    if ($reason !== '') {
                        if (!isset($reasonCount[$reason])) {
                            $reasons[] = $reason;
                            $reasonCount[$reason] = 0;
                            $reasonBarcode[$reason] = trim((string)($line['asn']['ean_upc'] ?? ''));
                            $actionOf[$reason] = trim((string)($line['action'] ?? ''));
                        }
                        $reasonCount[$reason]++;
                    }
                }
            }

            // The problem: a line that is fine itself carries the culprit sentence of the bill, which already names the
            // barcodes. Otherwise a reason that only one line has belongs to that line, so it says which barcode it is,
            // except while the bill waits for the fix: then the reason is about its pages. The solution is the action
            // of the lines the problem quotes; a wrong line has none.
            $waiting = $status === BillStatus::PENDING_FIX;
            $problem = $culprit;
            $solutions = [];
            if ($culprit === '' && !empty($reasons)) {
                $parts = [];
                foreach ($reasons as $reason) {
                    $ownLine = !$waiting && $reasonCount[$reason] === 1 && count($lines[$invoice]) > 1 && $reasonBarcode[$reason] !== '';
                    $parts[] = ($ownLine ? 'บาร์โค้ด ' . $reasonBarcode[$reason] . ': ' : '') . $reason;
                    $solutions[] = $actionOf[$reason];
                }
                $problem = implode(FixLog::TOPICS, array_slice($parts, 0, 3)) . (count($parts) > 3 ? ' และอีก ' . (count($parts) - 3) . ' ประเด็น' : '');
                $solutions = array_slice($solutions, 0, 3);
            }
            $detail = implode("\n", $reasons);

            // The ASN repeats the invoice's Total Cost Ex VAT on every line; the first positive one stands for it
            $cost = null;
            foreach ($lines[$invoice] as $line) {
                if ((float)($line['asn']['total_cost_ex_vat'] ?? 0) > 0) {
                    $cost = (float)$line['asn']['total_cost_ex_vat'];
                    break;
                }
            }

            $row = [
                'invoice_no' => $invoice,
                'branch_code' => $found['branch'] ?? null,
                'store_no' => ($stores[$invoice] ?? '') !== '' ? $stores[$invoice] : null,
                'source_files' => self::sourceFiles($pages, $invoice),
                'units' => $found ? $found['units'] : null,
                'pages' => $found ? array_sum(array_map('count', $filePages)) : null,
                'total_cost_ex_vat' => $cost,
                'status' => $status,
                'fixed' => $wasFixed,
                // What the fix did, with no "แก้ไขแล้ว:" in front (the user's call): the Status column shows the fix
                'note' => $wasFixed ? $fixed[$invoice] : '',
                'problem' => $problem,
                'solution' => implode(FixLog::TOPICS, array_values(array_unique(array_filter($solutions, 'strlen')))),
                'note_detail' => $detail,
            ];
            if ($figures !== null) {
                $row += self::sourceColumns($lines[$invoice], $figures->bill(self::invoiceKey($invoice)));
            }
            $was = BillHistory::was($history[$invoice] ?? []);
            if (!empty($was)) {
                $row['was'] = $was;
            }
            if (!empty($history[$invoice])) {
                $row['history'] = $history[$invoice];
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
