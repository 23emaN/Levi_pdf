<?php

namespace App;

use RuntimeException;

/**
 * Copies the invoices that are ready into numbered rounds under the Job's print folder.
 * A round is built in a hidden temporary folder and renamed when complete, so a half-built round never shows.
 * Which invoices are already printed is read from the files in the print folder, not stored anywhere else.
 */
class PrintManager
{
    private const NO_STORE = 'ไม่ระบุสาขา';

    /** @var JobManager */
    private $jobs;

    /** @var PdfSplitter */
    private $splitter;

    public function __construct(JobManager $jobs, ?PdfSplitter $splitter = null)
    {
        $this->jobs = $jobs;
        $this->splitter = $splitter ?? new PdfSplitter();
    }

    /**
     * Round folders that exist, as round number => folder path
     */
    private function roundFolders(string $printDir): array
    {
        $rounds = [];
        foreach (is_dir($printDir) ? scandir($printDir) : [] as $name) {
            if (preg_match('/^' . preg_quote(JobFolders::PRINT_ROUND_PREFIX, '/') . '(\d+)$/u', $name, $m) && is_dir($printDir . DIRECTORY_SEPARATOR . $name)) {
                $rounds[(int)$m[1]] = $printDir . DIRECTORY_SEPARATOR . $name;
            }
        }
        ksort($rounds);
        return $rounds;
    }

    public function roundCount(int $jobId): int
    {
        return count($this->roundFolders($this->jobs->getJobPrintDir($jobId, false)));
    }

    public function nextRound(int $jobId): int
    {
        $rounds = $this->roundFolders($this->jobs->getJobPrintDir($jobId, false));
        return empty($rounds) ? 1 : max(array_keys($rounds)) + 1;
    }

    /**
     * Invoice => round number for every invoice that has a file in a round folder.
     * Files are named {sequence}_{invoice}.pdf, zero padded in rounds made before numbering dropped the padding.
     */
    public function printedInvoices(int $jobId): array
    {
        $printed = [];
        foreach ($this->roundFolders($this->jobs->getJobPrintDir($jobId, false)) as $round => $folder) {
            foreach (scandir($folder) as $store) {
                $storeDir = $folder . DIRECTORY_SEPARATOR . $store;
                if ($store === '.' || $store === '..' || !is_dir($storeDir)) {
                    continue;
                }
                foreach (scandir($storeDir) as $file) {
                    if (preg_match('/^\d+_(.+)\.pdf$/i', $file, $m) && !isset($printed[$m[1]])) {
                        $printed[$m[1]] = $round;
                    }
                }
            }
        }
        return $printed;
    }

    private function storeFolder(string $store): string
    {
        $clean = (string)preg_replace('/[\\\\\/:*?"<>|]+/', '_', trim($store));
        return $clean === '' ? self::NO_STORE : $clean;
    }

    /**
     * What a print would do now: invoices ready (BillStatus::canPrint) and not printed yet, with the counts of what is
     * left out by status (pending_asn only when there is one) and of what was printed before
     */
    public function plan(int $jobId): array
    {
        $results = $this->jobs->reconcileJob($jobId)['results'];
        $printed = $this->printedInvoices($jobId);
        $stores = AsnReconciler::invoiceStores($results);
        $skipped = ['pending_fix' => 0, 'mismatch' => 0, 'printed' => 0];
        $ready = [];
        foreach (AsnReconciler::invoiceStatuses($results) as $invoice => $status) {
            if (!BillStatus::canPrint($status)) {
                $skipped[strtolower($status)] = ($skipped[strtolower($status)] ?? 0) + 1;
            } elseif (isset($printed[$invoice])) {
                $skipped['printed']++;
            } else {
                $ready[$invoice] = $this->storeFolder($stores[(string)$invoice] ?? '');
            }
        }

        uksort($ready, function ($a, $b) use ($ready) {
            $byStore = strcmp($ready[$a], $ready[$b]);
            return $byStore !== 0 ? $byStore : strnatcmp($a, $b);
        });

        $round = $this->nextRound($jobId);
        return [
            'round' => $round,
            'folder' => JobFolders::displayPath($this->jobs->getJobPrintRoundDir($jobId, $round)),
            'ready' => $ready,
            'ready_count' => count($ready),
            'store_count' => count(array_unique($ready)),
            'skipped' => $skipped,
        ];
    }

    private function metaPath(string $tmpDir, string $name): string
    {
        return $tmpDir . DIRECTORY_SEPARATOR . $name;
    }

    private function tmpDirFor(int $jobId, string $token): string
    {
        if (!preg_match('/^[a-f0-9]{12}$/', $token)) {
            throw new RuntimeException('Invalid print token');
        }
        $dir = $this->jobs->getJobPrintDir($jobId, false) . DIRECTORY_SEPARATOR . '.tmp_' . $token;
        if (!is_dir($dir)) {
            throw new RuntimeException('Print run not found or already finished');
        }
        return $dir;
    }

    /**
     * Begin a round: fix the list of invoices and open a hidden working folder
     */
    public function start(int $jobId): array
    {
        $plan = $this->plan($jobId);
        $printDir = $this->jobs->getJobPrintDir($jobId);
        foreach (glob($printDir . DIRECTORY_SEPARATOR . '.tmp_*') ?: [] as $stale) {
            if (is_dir($stale) && filemtime($stale) < time() - 3600) {
                $this->jobs->deleteDirectoryRecursive($stale);
            }
        }
        if ($plan['ready_count'] === 0) {
            return ['token' => null, 'total' => 0, 'plan' => $plan];
        }

        $token = bin2hex(random_bytes(6));
        $tmp = $printDir . DIRECTORY_SEPARATOR . '.tmp_' . $token;
        mkdir($tmp, 0777, true);
        $entries = [];
        foreach ($plan['ready'] as $invoice => $store) {
            $entries[] = ['invoice' => $invoice, 'store' => $store];
        }
        file_put_contents($this->metaPath($tmp, 'plan.json'), json_encode($entries, JSON_UNESCAPED_UNICODE));
        file_put_contents($this->metaPath($tmp, 'progress.json'), json_encode(['done' => [], 'failed' => []], JSON_UNESCAPED_UNICODE));

        return ['token' => $token, 'total' => count($entries), 'plan' => $plan];
    }

    /**
     * Put one invoice's PDF at $target: the corrected file from finished, the original when it holds only this
     * invoice and needs no edits, otherwise its pages cut from the original with the same edits the fix applies
     */
    private function buildInvoicePdf(array $job, string $invoice, string $target): void
    {
        $jobId = (int)$job['id'];
        $finishedDir = $this->jobs->getJobFinishedDir($job, false);
        $ready = $finishedDir . DIRECTORY_SEPARATOR . $invoice . '.pdf';
        if (is_file($ready)) {
            if (!copy($ready, $target)) {
                throw new RuntimeException("คัดลอกไฟล์ {$invoice}.pdf ไม่สำเร็จ");
            }
            return;
        }

        $stmt = Database::getConnection()->prepare("SELECT filename, page_no FROM job_items WHERE job_id = :job_id AND tax_invoice = :inv GROUP BY filename, page_no ORDER BY filename, page_no");
        $stmt->execute([':job_id' => $jobId, ':inv' => $invoice]);
        $pagesByFile = [];
        while ($row = $stmt->fetch()) {
            $pagesByFile[$row['filename']][] = (int)$row['page_no'];
        }
        if (empty($pagesByFile)) {
            throw new RuntimeException("ไม่พบข้อมูลของบิล {$invoice}");
        }

        $filename = array_key_first($pagesByFile);
        $pages = $pagesByFile[$filename];
        $source = $finishedDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($source)) {
            throw new RuntimeException("ไม่พบไฟล์ต้นฉบับ {$filename}");
        }

        $edits = $this->splitter->planInvoiceEdits($source, $pages, $invoice);
        if (!PdfSplitter::hasEdits($edits) && count($pages) === $this->splitter->pageCount($source)) {
            if (!copy($source, $target)) {
                throw new RuntimeException("คัดลอกไฟล์ {$filename} ไม่สำเร็จ");
            }
            return;
        }
        $this->splitter->extractPages($source, $pages, $target, $edits);
    }

    /**
     * Build a slice of the planned invoices into the working folder
     */
    public function processChunk(int $jobId, string $token, int $offset, int $limit): array
    {
        $tmp = $this->tmpDirFor($jobId, $token);
        $job = $this->jobs->getJob($jobId);
        $entries = json_decode((string)file_get_contents($this->metaPath($tmp, 'plan.json')), true) ?: [];
        $progress = json_decode((string)file_get_contents($this->metaPath($tmp, 'progress.json')), true) ?: ['done' => [], 'failed' => []];

        foreach (array_slice($entries, max(0, $offset), max(1, $limit)) as $entry) {
            $storeDir = $tmp . DIRECTORY_SEPARATOR . $entry['store'];
            if (!is_dir($storeDir)) {
                mkdir($storeDir, 0777, true);
            }
            try {
                $this->buildInvoicePdf($job, $entry['invoice'], $storeDir . DIRECTORY_SEPARATOR . $entry['invoice'] . '.pdf');
                $progress['done'][$entry['invoice']] = true;
            } catch (\Throwable $e) {
                @unlink($storeDir . DIRECTORY_SEPARATOR . $entry['invoice'] . '.pdf');
                $progress['failed'][$entry['invoice']] = $e->getMessage();
            }
        }
        file_put_contents($this->metaPath($tmp, 'progress.json'), json_encode($progress, JSON_UNESCAPED_UNICODE));

        return ['done' => count($progress['done']), 'failed_count' => count($progress['failed']), 'total' => count($entries)];
    }

    /**
     * Number the files, name the round and publish it. Nothing is published when no invoice could be built.
     */
    public function finish(int $jobId, string $token): array
    {
        $tmp = $this->tmpDirFor($jobId, $token);
        $progress = json_decode((string)file_get_contents($this->metaPath($tmp, 'progress.json')), true) ?: ['done' => [], 'failed' => []];
        $failed = [];
        foreach ($progress['failed'] as $invoice => $reason) {
            $failed[] = ['invoice' => $invoice, 'reason' => $reason];
        }

        if (empty($progress['done'])) {
            $this->jobs->deleteDirectoryRecursive($tmp);
            return ['round' => null, 'created' => 0, 'stores' => 0, 'failed' => $failed, 'folder' => null];
        }

        @unlink($this->metaPath($tmp, 'plan.json'));
        @unlink($this->metaPath($tmp, 'progress.json'));

        $stores = 0;
        foreach (scandir($tmp) as $store) {
            $storeDir = $tmp . DIRECTORY_SEPARATOR . $store;
            if ($store === '.' || $store === '..' || !is_dir($storeDir)) {
                continue;
            }
            $files = array_values(array_filter(scandir($storeDir), function ($f) {
                return strtolower(substr($f, -4)) === '.pdf';
            }));
            if (empty($files)) {
                $this->jobs->deleteDirectoryRecursive($storeDir);
                continue;
            }
            usort($files, function ($a, $b) {
                return strnatcmp(substr($a, 0, -4), substr($b, 0, -4));
            });
            foreach ($files as $i => $file) {
                rename($storeDir . DIRECTORY_SEPARATOR . $file, $storeDir . DIRECTORY_SEPARATOR . ($i + 1) . '_' . $file);
            }
            $stores++;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $round = $this->nextRound($jobId);
            $target = $this->jobs->getJobPrintRoundDir($jobId, $round);
            if (@rename($tmp, $target)) {
                return ['round' => $round, 'created' => count($progress['done']), 'stores' => $stores, 'failed' => $failed, 'folder' => JobFolders::displayPath($target)];
            }
            usleep(50000);
        }
        throw new RuntimeException('สร้างโฟลเดอร์รอบการสั่งพิมพ์ไม่สำเร็จ');
    }

    /**
     * Whole round in one call, for tests and scripts
     */
    public function runAll(int $jobId, int $chunk = 40): array
    {
        $started = $this->start($jobId);
        if ($started['token'] === null) {
            return ['round' => null, 'created' => 0, 'stores' => 0, 'failed' => [], 'folder' => null];
        }
        for ($offset = 0; $offset < $started['total']; $offset += $chunk) {
            $this->processChunk($jobId, $started['token'], $offset, $chunk);
        }
        return $this->finish($jobId, $started['token']);
    }
}
