<?php

namespace App;

use InvalidArgumentException;
use PDO;

/**
 * Where each Tax Invoice bill of a Job came from: one row per change of its category after a user action, written by
 * JobManager::recordBillHistory (the user's decision of 2026-10-28). A category is a BillStatus code, or FIXED for a bill
 * that matches and that the fix button worked on. The first row of a bill has no from_status; nothing before the first
 * recorded action is known. The table is created here (not in Database::initSchema), as VbrpStore does, so a database
 * made before it works at once.
 */
final class BillHistory
{
    public const FIXED = 'FIXED';

    public const EVENTS = ['compare', 'asn_import', 'asn_delete', 'vbrp_import', 'vbrp_clear', 'fix'];

    /** The categories that tell where a bill came from */
    private const EARLIER = [BillStatus::PENDING_FIX, BillStatus::PENDING_ASN, BillStatus::MISMATCH];

    private const DETAIL_LENGTH = 300;

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        self::createTable($db);
    }

    public static function createTable(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS job_bill_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                invoice_no TEXT NOT NULL,
                at DATETIME DEFAULT CURRENT_TIMESTAMP,
                event TEXT NOT NULL,
                from_status TEXT,
                to_status TEXT NOT NULL,
                detail TEXT NOT NULL DEFAULT '',
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_bill_history_job_invoice ON job_bill_history(job_id, invoice_no, id);
        ");
    }

    public static function category(string $status, bool $fixed): string
    {
        return $status === BillStatus::MATCH && $fixed ? self::FIXED : $status;
    }

    /**
     * One row per bill whose category differs from its last row, or that has none; the same bills again write nothing
     *
     * @param array $bills invoice number as the ASN writes it => ['category' => string, 'detail' => its problem or '']
     * @return int rows written
     */
    public function record(int $jobId, string $event, array $bills): int
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException("Unknown bill history event {$event}");
        }
        $last = [];
        foreach ($this->forJob($jobId) as $invoice => $rows) {
            $last[(string)$invoice] = $rows[count($rows) - 1]['to'];
        }
        $insert = $this->db->prepare('INSERT INTO job_bill_history (job_id, invoice_no, event, from_status, to_status, detail) VALUES (:job_id, :invoice, :event, :from, :to, :detail)');
        $written = 0;
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($bills as $invoice => $bill) {
                $from = $last[(string)$invoice] ?? null;
                if ($from === $bill['category']) {
                    continue;
                }
                $insert->execute([
                    ':job_id' => $jobId, ':invoice' => (string)$invoice, ':event' => $event, ':from' => $from,
                    ':to' => $bill['category'], ':detail' => self::cut((string)$bill['detail']),
                ]);
                $written++;
            }
            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Exception $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $written;
    }

    /**
     * @return array invoice number => rows ['at', 'event', 'from', 'to', 'detail'], oldest first
     */
    public function forJob(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT invoice_no, at, event, from_status AS `from`, to_status AS `to`, detail FROM job_bill_history WHERE job_id = :job_id ORDER BY id');
        $stmt->execute([':job_id' => $jobId]);
        $history = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoice = $row['invoice_no'];
            unset($row['invoice_no']);
            $history[$invoice][] = $row;
        }
        return $history;
    }

    /**
     * The waiting and wrong categories a bill had before its latest row, first seen first, each once
     *
     * @param array $rows a bill's rows from forJob()
     * @return string[]
     */
    public static function was(array $rows): array
    {
        $was = [];
        foreach (array_slice($rows, 0, -1) as $row) {
            if (in_array($row['to'], self::EARLIER, true) && !in_array($row['to'], $was, true)) {
                $was[] = $row['to'];
            }
        }
        return $was;
    }

    public function clear(int $jobId): void
    {
        $stmt = $this->db->prepare('DELETE FROM job_bill_history WHERE job_id = :job_id');
        $stmt->execute([':job_id' => $jobId]);
    }

    private static function cut(string $text): string
    {
        return preg_match('/^.{0,' . self::DETAIL_LENGTH . '}/su', $text, $m) ? $m[0] : '';
    }
}
