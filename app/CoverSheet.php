<?php

namespace App;

use PDO;
use RuntimeException;

/**
 * The cover sheet (ใบปะหน้า "สรุปการนำส่งเอกสารทำคืน-เข้าใหม่") of a Job: every complete Tax Invoice and every complete
 * credit note of the Job, grouped by store. Within a store the two lists are sorted on their own and set side by side
 * row by row; where one list is shorter its cells stay blank. Each store starts a page, a page holds 19 rows and the last
 * page of a store carries the two totals.
 *
 * Complete means what the compare of each system says: a Tax Invoice that BillStatus::canPrint (complete or fixed) in
 * a Job compared with its ASN, a credit note whose verdict is MATCH in a Job whose credit notes were compared.
 * Printing a round does not matter: every complete document of the Job is on the sheet.
 */
class CoverSheet
{
    public const ROWS_PER_PAGE = 19;
    public const NO_STORE = 'ไม่ระบุสาขา';
    /** What a cell without a document prints: nothing (the user's choice of 2026-11-10, "-" before) */
    public const EMPTY_CELL = '';

    /** The header fields the page lets the user change, with the values of the sample sheet */
    public const DEFAULT_SETTINGS = [
        'contact' => 'คุณปาล์ม',
        'mobile' => '061-403-8024',
        'case_label' => 'สินค้าจัดรายการ',
        'date' => '',
    ];

    private const SETTINGS_MAX_LENGTH = 60;
    private const TIMEZONE = 'Asia/Bangkok';

    /** @var JobManager */
    private $jobs;

    /** @var PDO */
    private $db;

    /** @var StoreNames */
    private $names;

    public function __construct(JobManager $jobs, ?StoreNames $names = null)
    {
        $this->jobs = $jobs;
        $this->db = Database::getConnection();
        $this->names = $names ?? new StoreNames();
        $this->createTable();
    }

    private function createTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS cover_settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    }

    /**
     * The stores of the sheet with their documents, and what was left out
     */
    public function build(int $jobId): array
    {
        $job = $this->jobs->getJob($jobId);
        if (!$job) {
            throw new RuntimeException('ไม่พบ Job นี้');
        }

        $taxCompared = (int)($job['asn_reconciled'] ?? 0) === 1;
        $invoices = [];
        $taxSkipped = 0;
        if ($taxCompared) {
            $results = $this->jobs->reconcileJob($jobId)['results'];
            $stores = AsnReconciler::invoiceStores($results);
            foreach (AsnReconciler::invoiceStatuses($results) as $invoice => $status) {
                if (BillStatus::canPrint($status)) {
                    $invoices[] = ['no' => (string)$invoice, 'store' => (string)($stores[(string)$invoice] ?? '')];
                } else {
                    $taxSkipped++;
                }
            }
        }

        $cnDashboard = $this->jobs->creditNoteCompare()->dashboard($jobId);
        $cnCompared = (bool)$cnDashboard['compared'];
        $creditNotes = [];
        $cnSkipped = 0;
        if ($cnCompared) {
            foreach ($cnDashboard['documents'] as $doc) {
                if ($doc['status'] === 'MATCH') {
                    $creditNotes[] = ['no' => (string)$doc['doc_no'], 'store' => (string)($doc['store_no'] ?? '')];
                } else {
                    $cnSkipped++;
                }
            }
        }

        $this->names->learnFromCreditNotes($this->jobs->getCreditNotes($jobId));
        $groups = self::group($invoices, $creditNotes);
        foreach ($groups as &$group) {
            $group['store_name'] = $group['store_no'] === '' ? '' : $this->names->name($group['store_no']);
        }
        unset($group);

        return [
            'job' => ['id' => (int)$job['id'], 'name' => (string)$job['name']],
            'tax_compared' => $taxCompared,
            'cn_compared' => $cnCompared,
            'stores' => $groups,
            'skipped' => ['invoices' => $taxSkipped, 'credit_notes' => $cnSkipped],
        ];
    }

    /**
     * Documents grouped by store (leading zeros do not make another store), stores in natural order with the documents
     * that name no store last, each list sorted by number
     *
     * @param array $invoices list of ['no' => number, 'store' => store number]
     * @param array $creditNotes the same for credit notes
     */
    public static function group(array $invoices, array $creditNotes): array
    {
        $groups = [];
        foreach (['invoices' => $invoices, 'credit_notes' => $creditNotes] as $side => $docs) {
            foreach ($docs as $doc) {
                $store = trim((string)$doc['store']);
                $key = StoreNames::key($store);
                if (!isset($groups[$key])) {
                    $groups[$key] = ['store_no' => $store, 'invoices' => [], 'credit_notes' => []];
                }
                $groups[$key][$side][] = (string)$doc['no'];
            }
        }
        foreach ($groups as &$group) {
            $group['invoices'] = array_values(array_unique($group['invoices']));
            $group['credit_notes'] = array_values(array_unique($group['credit_notes']));
            usort($group['invoices'], 'strnatcmp');
            usort($group['credit_notes'], 'strnatcmp');
        }
        unset($group);
        uksort($groups, function ($a, $b) {
            if (($a === '') !== ($b === '')) {
                return $a === '' ? 1 : -1;
            }
            return strnatcmp((string)$a, (string)$b);
        });
        return array_values($groups);
    }

    /**
     * The printed pages: a store starts a page, a page holds $perPage rows numbered on from the page before within the
     * store, and the last page of a store carries the totals of both lists. A missing cell is null.
     */
    public static function pages(array $stores, int $perPage = self::ROWS_PER_PAGE): array
    {
        $pages = [];
        foreach ($stores as $store) {
            $count = max(count($store['invoices']), count($store['credit_notes']));
            if ($count === 0) {
                continue;
            }
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'seq' => $i + 1,
                    'invoice' => $store['invoices'][$i] ?? null,
                    'credit_note' => $store['credit_notes'][$i] ?? null,
                ];
            }
            $chunks = array_chunk($rows, $perPage);
            foreach ($chunks as $n => $chunk) {
                $last = $n === count($chunks) - 1;
                $pages[] = [
                    'store_no' => $store['store_no'] === '' ? self::NO_STORE : $store['store_no'],
                    'store_name' => (string)($store['store_name'] ?? ''),
                    'rows' => $chunk,
                    'page_of_store' => $n + 1,
                    'store_pages' => count($chunks),
                    'totals' => $last ? ['invoices' => count($store['invoices']), 'credit_notes' => count($store['credit_notes'])] : null,
                ];
            }
        }
        return $pages;
    }

    /**
     * What the page shows before the PDF is made: counts per store, pages and stores without a name
     */
    public function preview(int $jobId): array
    {
        $sheet = $this->build($jobId);
        $pages = self::pages($sheet['stores']);
        $pagesByStore = [];
        foreach ($pages as $page) {
            $pagesByStore[$page['store_no']] = $page['store_pages'];
        }
        $stores = [];
        $unnamed = [];
        foreach ($sheet['stores'] as $store) {
            $label = $store['store_no'] === '' ? self::NO_STORE : $store['store_no'];
            $stores[] = [
                'store_no' => $label,
                'store_name' => $store['store_name'],
                'invoices' => count($store['invoices']),
                'credit_notes' => count($store['credit_notes']),
                'pages' => $pagesByStore[$label] ?? 0,
            ];
            if ($store['store_name'] === '') {
                $unnamed[] = $label;
            }
        }
        return [
            'tax_compared' => $sheet['tax_compared'],
            'cn_compared' => $sheet['cn_compared'],
            'stores' => $stores,
            'unnamed' => $unnamed,
            'skipped' => $sheet['skipped'],
            'totals' => [
                'invoices' => array_sum(array_column($stores, 'invoices')),
                'credit_notes' => array_sum(array_column($stores, 'credit_notes')),
                'pages' => count($pages),
            ],
            'settings' => $this->settings(),
            'files' => $this->files($jobId),
        ];
    }

    /**
     * Make the PDF in the Job's cover folder with these header values (kept for the next sheet)
     */
    public function generate(int $jobId, array $settings): array
    {
        $settings = $this->saveSettings($settings);
        $sheet = $this->build($jobId);
        $pages = self::pages($sheet['stores']);
        if (empty($pages)) {
            throw new RuntimeException('ไม่มีเอกสารที่สมบูรณ์ให้ทำใบปะหน้า');
        }
        $dir = $this->jobs->getJobCoverDir($jobId);
        $name = 'ใบปะหน้า_' . self::localTime(time(), 'Ymd_His') . '.pdf';
        (new CoverSheetPdf())->render($pages, $settings, $dir . DIRECTORY_SEPARATOR . $name);
        return [
            'file' => $name,
            'folder' => JobFolders::displayPath($dir),
            'pages' => count($pages),
            'stores' => count($sheet['stores']),
            'invoices' => array_sum(array_map('count', array_column($sheet['stores'], 'invoices'))),
            'credit_notes' => array_sum(array_map('count', array_column($sheet['stores'], 'credit_notes'))),
        ];
    }

    /**
     * The cover sheets made for the Job, newest first
     */
    public function files(int $jobId): array
    {
        $dir = $this->jobs->getJobCoverDir($jobId, false);
        $files = [];
        foreach (is_dir($dir) ? scandir($dir) : [] as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
                $files[] = ['file' => $file, 'size' => filesize($path), 'modified' => self::localTime((int)filemtime($path), 'Y-m-d H:i:s')];
            }
        }
        usort($files, function ($a, $b) {
            return strcmp($b['modified'], $a['modified']) ?: strcmp($b['file'], $a['file']);
        });
        return $files;
    }

    /**
     * The full path of one cover sheet of the Job, null for a name that is not one
     */
    public function filePath(int $jobId, string $file): ?string
    {
        if ($file === '' || $file !== basename($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }
        $path = $this->jobs->getJobCoverDir($jobId, false) . DIRECTORY_SEPARATOR . $file;
        return is_file($path) ? $path : null;
    }

    /** Times the user reads (file names, the list of files) in Thai time, whatever the timezone of php.ini */
    private static function localTime(int $timestamp, string $format): string
    {
        return (new \DateTime('@' . $timestamp))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format($format);
    }

    public function settings(): array
    {
        $saved = [];
        foreach ($this->db->query('SELECT name, value FROM cover_settings') as $row) {
            $saved[$row['name']] = $row['value'];
        }
        return array_intersect_key($saved, self::DEFAULT_SETTINGS) + self::DEFAULT_SETTINGS;
    }

    private function saveSettings(array $settings): array
    {
        $clean = [];
        foreach (self::DEFAULT_SETTINGS as $name => $default) {
            $value = trim((string)($settings[$name] ?? $default));
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = $default;
            }
            $clean[$name] = mb_substr(preg_replace('/\s+/u', ' ', $value), 0, self::SETTINGS_MAX_LENGTH);
        }
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO cover_settings (name, value) VALUES (:name, :value)');
        foreach ($clean as $name => $value) {
            $stmt->execute([':name' => $name, ':value' => $value]);
        }
        return $clean;
    }
}
