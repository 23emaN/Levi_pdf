<?php

namespace App;

use PDO;

/**
 * What the fix button did to the pages of a Job, kept so the remarks can tell the customer.
 * One row per page and kind: a page is renumbered at most once, loses its footer at most once and is written into
 * one invoice PDF. invoice_no is the invoice the page ends up in, from_value the number a renumbered page carried
 * before its first fix.
 */
class FixLog
{
    public const RENUMBER = 'renumber';
    public const CLEAR_FOOTER = 'clear_footer';
    public const SPLIT = 'split';

    /** Between the topics of a remark (the user's choice); a page list inside one topic uses "และ" instead */
    public const TOPICS = ', ';

    /** Not stored: the renumber step as seen from the invoice that lost the page */
    public const MOVED_AWAY = 'moved_away';

    /**
     * Remark phrase per step, in remark order: [sprintf pattern, page word, run joiner]. %1$s pages, %2$s the other
     * invoice, %3$s the invoice itself; the pages read "<page word> 1<run joiner>3 และ 5"
     */
    private const PHRASES = [
        self::RENUMBER => ['แก้เลขบิลจาก%1$s (%2$s เป็น %3$s)', 'หน้า', '-'],
        self::MOVED_AWAY => ['%1$s ถูกย้ายไปเป็น %2$s', 'หน้า', '-'],
        self::CLEAR_FOOTER => ['ลบยอดรวมท้ายบิลจาก%1$s', 'หน้า', '-'],
        self::SPLIT => ['แยกออกมาจาก%1$s', 'หน้า', '-'],
    ];

    /** The solution before the fix runs (FixPlan), in the user's wording; same order and shape as PHRASES */
    public const PLANNED = [
        self::RENUMBER => ['แก้ไขเลขบิลของ%1$s', 'หน้า', ' ถึง '],
        self::MOVED_AWAY => ['ย้าย%1$s ไปเป็นบิล %2$s', 'หน้า', ' ถึง '],
        self::CLEAR_FOOTER => ['ลบยอดรวมของ%1$s', 'หน้าที่', ' ถึง '],
        self::SPLIT => ['แยก%1$s ออกมาเป็นอีกไฟล์', 'หน้าที่', ' ถึง '],
    ];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Record steps; repeating a step changes nothing, a page moved to another invoice by a later run follows it
     *
     * @param array $steps list of ['kind', 'filename', 'page', 'invoice', 'from' (renumber only)]
     */
    public function record(int $jobId, array $steps): void
    {
        if (empty($steps)) {
            return;
        }
        $where = "job_id = :job_id AND filename = :filename AND page_no = :page AND kind = :kind";
        $insert = $this->db->prepare("INSERT OR IGNORE INTO job_invoice_fix_steps (job_id, invoice_no, kind, filename, page_no, from_value) VALUES (:job_id, :invoice, :kind, :filename, :page, :from)");
        $owner = $this->db->prepare("UPDATE job_invoice_fix_steps SET invoice_no = :invoice WHERE {$where}");
        $undone = $this->db->prepare("DELETE FROM job_invoice_fix_steps WHERE {$where} AND from_value = invoice_no");

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        foreach ($steps as $step) {
            $key = [':job_id' => $jobId, ':filename' => (string)$step['filename'], ':page' => (int)$step['page'], ':kind' => $step['kind']];
            $insert->execute($key + [':invoice' => (string)$step['invoice'], ':from' => (string)($step['from'] ?? '')]);
            $owner->execute($key + [':invoice' => (string)$step['invoice']]);
            $undone->execute($key);
        }
        if ($ownTx) {
            $this->db->commit();
        }
    }

    /**
     * Invoices the fix has already split out, or recorded as fixed before its steps were logged. The plan lists their
     * splits again on every press, so for these a split alone is no reason to wait.
     *
     * @return array invoice key (JobManager::invoiceKey) => true
     */
    public function splitDone(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT invoice_no FROM job_invoice_fix_steps WHERE job_id = :steps AND kind = :split UNION SELECT invoice_no FROM job_invoice_fixes WHERE job_id = :fixes");
        $stmt->execute([':steps' => $jobId, ':split' => self::SPLIT, ':fixes' => $jobId]);
        $done = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $invoice) {
            $done[JobManager::invoiceKey((string)$invoice)] = true;
        }
        return $done;
    }

    /**
     * @param array $steps as given to record()
     * @return string[]
     */
    public static function invoicesTouched(array $steps): array
    {
        $invoices = [];
        foreach ($steps as $step) {
            $invoices[(string)$step['invoice']] = true;
            if ((string)($step['from'] ?? '') !== '') {
                $invoices[(string)$step['from']] = true;
            }
        }
        return array_map('strval', array_keys($invoices));
    }

    /**
     * What the fix did, as remark text per invoice, steps joined with TOPICS
     *
     * An invoice without logged steps was fixed before steps were logged, so only its split can be told,
     * from the pages its PDF rows sit on now.
     *
     * @param string[] $invoices
     * @param array $pdfPages invoice key (JobManager::invoiceKey) => [filename => page numbers] of the PDF rows now
     * @return array invoice => text, '' when nothing is known
     */
    public function describe(int $jobId, array $invoices, array $pdfPages): array
    {
        if (empty($invoices)) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT kind, filename, page_no AS page, invoice_no AS invoice, from_value AS `from` FROM job_invoice_fix_steps WHERE job_id = :job_id ORDER BY filename, page_no");
        $stmt->execute([':job_id' => $jobId]);
        $steps = self::byInvoice($stmt->fetchAll(PDO::FETCH_ASSOC));

        $texts = [];
        foreach ($invoices as $invoice) {
            $invoice = (string)$invoice;
            $key = JobManager::invoiceKey($invoice);
            $current = $pdfPages[$key] ?? [];
            $own = $steps[$key] ?? (empty($current) ? [] : [self::SPLIT => ['' => $current]]);
            $texts[$invoice] = self::sentences($own, $invoice, self::PHRASES, array_keys($current));
        }
        return $texts;
    }

    /**
     * Steps by invoice key => kind => other invoice => filename => pages; a renumbered page also counts as moved
     * away from the invoice whose number it carried
     *
     * @param array $steps as given to record()
     */
    public static function byInvoice(array $steps): array
    {
        $grouped = [];
        foreach ($steps as $step) {
            $page = (int)$step['page'];
            if ($step['kind'] === self::RENUMBER) {
                $grouped[JobManager::invoiceKey((string)$step['invoice'])][self::RENUMBER][(string)$step['from']][$step['filename']][] = $page;
                $grouped[JobManager::invoiceKey((string)$step['from'])][self::MOVED_AWAY][(string)$step['invoice']][$step['filename']][] = $page;
            } else {
                $grouped[JobManager::invoiceKey((string)$step['invoice'])][$step['kind']][''][$step['filename']][] = $page;
            }
        }
        return $grouped;
    }

    /**
     * One invoice's steps as text, a phrase per kind and other invoice in phrase order, joined with TOPICS
     *
     * @param array $own kind => other invoice => filename => pages, from byInvoice()
     * @param array $phrases kind => [sprintf pattern, page word, run joiner], see PHRASES
     * @param string[] $files more files the invoice is on; pages say their file once more than one is involved
     */
    public static function sentences(array $own, string $invoice, array $phrases, array $files = []): string
    {
        foreach ($own as $byOther) {
            foreach ($byOther as $byFile) {
                $files = array_merge($files, array_keys($byFile));
            }
        }
        $named = count(array_unique($files)) > 1;

        $parts = [];
        foreach ($phrases as $kind => [$pattern, $word, $through]) {
            foreach ($own[$kind] ?? [] as $other => $byFile) {
                $parts[] = sprintf($pattern, self::pagesText($byFile, $named, $word, $through), $other, $invoice);
            }
        }
        return implode(self::TOPICS, $parts);
    }

    /**
     * "หน้า 1-3 และ 5", or "หน้า 1-2 ของ a.pdf และ หน้า 1 ของ b.pdf" when the file has to be named; never a comma,
     * which separates topics
     *
     * @param array $byFile filename => page numbers
     * @param string $word what stands before the pages of each file, "หน้า" or "หน้าที่"
     * @param string $through what joins the first and the last page of a run, "-" or " ถึง "
     */
    public static function pagesText(array $byFile, bool $named, string $word = 'หน้า', string $through = '-'): string
    {
        $parts = [];
        foreach ($byFile as $filename => $pages) {
            $parts[] = $word . ' ' . self::pageRanges($pages, $through) . ($named ? ' ของ ' . $filename : '');
        }
        return implode(' และ ', $parts);
    }

    private static function pageRanges(array $pages, string $through): string
    {
        $pages = array_values(array_unique(array_map('intval', $pages)));
        sort($pages);
        $ranges = [];
        $start = null;
        foreach ($pages as $i => $page) {
            $start = $start ?? $page;
            if (!isset($pages[$i + 1]) || $pages[$i + 1] !== $page + 1) {
                $ranges[] = $start === $page ? (string)$page : $start . $through . $page;
                $start = null;
            }
        }
        return implode(' และ ', $ranges);
    }
}
