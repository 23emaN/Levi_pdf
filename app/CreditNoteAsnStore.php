<?php

namespace App;

use PDO;

/**
 * The ASN rows of the credit note compare (job_cn_asn_records) and whether the Job's credit notes were compared
 * (job_cn_state). An import is one sheet of a workbook, its source is '<workbook> / <sheet>'; the skip and replace
 * rules are those of AsnStore::saveAsnRecords. The Tax Invoice ASN rows and the jobs table are never touched.
 */
class CreditNoteAsnStore
{
    public const AMOUNT_KINDS = ['cost', 'retail'];

    private const STORED = ['invoice_no', 'invoice_date', 'gp_date', 'exp_delivery_date', 'ship_via', 'trailer_nbr', 'dest_store_no', 'ean_upc', 'quantity', 'selling_price', 'total_cost_ex_vat', 'total_vat', 'delivery_no', 'amount_kind'];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function counts(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT source_file) AS files, COUNT(*) AS rows FROM job_cn_asn_records WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['files' => (int)$row['files'], 'rows' => (int)$row['rows']];
    }

    /**
     * The Job's ASN label the page shows: null without rows, the one source, or "N ชีต: a, b" in import order
     */
    public function label(int $jobId): ?string
    {
        $stmt = $this->db->prepare("SELECT source_file FROM job_cn_asn_records WHERE job_id = :job_id GROUP BY source_file ORDER BY MIN(id) ASC");
        $stmt->execute([':job_id' => $jobId]);
        $sources = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$sources) {
            return null;
        }
        return count($sources) === 1 ? (string)$sources[0] : count($sources) . ' ชีต: ' . implode(', ', $sources);
    }

    /**
     * Every ASN row of the Job in import order, as the page and the reconciler read them
     */
    public function records(int $jobId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, source_file, invoice_no, invoice_date, dest_store_no, ean_upc, quantity, selling_price,
                   total_cost_ex_vat, total_vat, delivery_no, amount_kind
            FROM job_cn_asn_records
            WHERE job_id = :job_id
            ORDER BY id ASC
        ");
        $stmt->execute([':job_id' => $jobId]);
        $records = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['id'] = (int)$row['id'];
            $row['quantity'] = (int)$row['quantity'];
            foreach (['selling_price', 'total_cost_ex_vat', 'total_vat'] as $field) {
                $row[$field] = (float)$row[$field];
            }
            $records[] = $row;
        }
        return $records;
    }

    /**
     * Credit note numbers of an incoming sheet that another source of the Job already holds
     *
     * @return array number => [sources]
     */
    public function findOverlap(int $jobId, string $source, array $invoiceNos): array
    {
        $wanted = array_flip(array_map('strval', $invoiceNos));
        $stmt = $this->db->prepare("SELECT DISTINCT invoice_no, source_file FROM job_cn_asn_records WHERE job_id = :job_id AND source_file != :sf");
        $stmt->execute([':job_id' => $jobId, ':sf' => $source]);
        $overlap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($wanted[$row['invoice_no']])) {
                $overlap[$row['invoice_no']][] = $row['source_file'];
            }
        }
        return $overlap;
    }

    public function describeStored(int $jobId, string $source, array $incomingRows): array
    {
        $stmt = $this->db->prepare("SELECT " . implode(', ', self::STORED) . " FROM job_cn_asn_records WHERE job_id = :job_id AND source_file = :sf");
        $stmt->execute([':job_id' => $jobId, ':sf' => $source]);
        $stored = array_map([self::class, 'signature'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $incoming = array_map(function (array $row): string {
            return self::signature(self::cleanRow($row));
        }, $incomingRows);
        sort($stored);
        sort($incoming);
        return [
            'stored' => count($stored),
            'incoming' => count($incoming),
            'identical' => $stored !== [] && $stored === $incoming,
            'reconciled' => $this->isReconciled($jobId),
        ];
    }

    /**
     * Stores the rows of one sheet. $append false replaces every row of the Job. Otherwise the earlier rows of the same
     * source go ($sameFileMode 'skip' keeps them and stores nothing), and a credit note number another source holds is
     * left out ($duplicateMode 'skip') or takes over from that source ('replace'). Any change of rows ends the compare.
     * A row whose amount_kind is not cost or retail refuses the whole import before anything is written.
     *
     * @return array imported (rows written), skipped_invoices, replaced_invoices, skipped_same_file, replaced_same_file
     */
    public function save(int $jobId, string $source, array $rows, bool $append, string $duplicateMode, string $sameFileMode): array
    {
        $clean = array_map([self::class, 'cleanRow'], $rows);
        $result = ['imported' => 0, 'skipped_invoices' => 0, 'replaced_invoices' => 0, 'skipped_same_file' => false, 'replaced_same_file' => false];
        if ($append && $sameFileMode === 'skip' && $this->describeStored($jobId, $source, [])['stored'] > 0) {
            $result['skipped_same_file'] = true;
            return $result;
        }

        $this->db->beginTransaction();
        try {
            if ($append) {
                $result['replaced_same_file'] = $this->deleteWhere($jobId, 'source_file = :sf', [':sf' => $source]) > 0;
                $overlap = $this->findOverlap($jobId, $source, array_column($clean, 'invoice_no'));
                if ($overlap && $duplicateMode === 'replace') {
                    foreach (array_keys($overlap) as $no) {
                        $this->deleteWhere($jobId, 'invoice_no = :inv AND source_file != :sf', [':inv' => (string)$no, ':sf' => $source]);
                    }
                    $result['replaced_invoices'] = count($overlap);
                } elseif ($overlap) {
                    $clean = array_values(array_filter($clean, function (array $row) use ($overlap): bool {
                        return !isset($overlap[$row['invoice_no']]);
                    }));
                    $result['skipped_invoices'] = count($overlap);
                }
            } else {
                $this->deleteWhere($jobId, '1 = 1', []);
            }

            $columns = array_merge(['job_id', 'source_file'], self::STORED, ['raw_json']);
            $insert = $this->db->prepare("INSERT INTO job_cn_asn_records (" . implode(', ', $columns) . ") VALUES (:" . implode(', :', $columns) . ")");
            foreach ($clean as $i => $row) {
                $params = [':job_id' => $jobId, ':source_file' => $source, ':raw_json' => $row['raw_json']];
                foreach (self::STORED as $field) {
                    $params[':' . $field] = $row[$field];
                }
                $insert->execute($params);
                $result['imported']++;
            }
            $this->setReconciled($jobId, false);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $jobId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function (int $id): bool {
            return $id > 0;
        })));
        $deleted = 0;
        $this->db->beginTransaction();
        try {
            foreach (array_chunk($ids, 450) as $chunk) {
                $deleted += $this->deleteWhere($jobId, 'id IN (' . implode(',', $chunk) . ')', []);
            }
            $this->db->commit();
            return $deleted;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function isReconciled(int $jobId): bool
    {
        $stmt = $this->db->prepare("SELECT asn_reconciled FROM job_cn_state WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public function setReconciled(int $jobId, bool $reconciled): void
    {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO job_cn_state (job_id, asn_reconciled, updated_at) VALUES (:job_id, :r, CURRENT_TIMESTAMP)");
        $stmt->execute([':job_id' => $jobId, ':r' => $reconciled ? 1 : 0]);
    }

    private function deleteWhere(int $jobId, string $where, array $params): int
    {
        $stmt = $this->db->prepare("DELETE FROM job_cn_asn_records WHERE job_id = :job_id AND ({$where})");
        $stmt->execute([':job_id' => $jobId] + $params);
        return $stmt->rowCount();
    }

    /**
     * A row of the request as it is stored; the request is the only place rows come from, so this is where they are
     * checked
     */
    private static function cleanRow(array $r): array
    {
        $kind = $r['amount_kind'] ?? null;
        if (!in_array($kind, self::AMOUNT_KINDS, true)) {
            throw new \InvalidArgumentException('แถว ASN ต้องบอกชนิดยอดเงิน (amount_kind) เป็น cost หรือ retail จึงยังไม่ได้นำเข้าแถวใด');
        }
        $number = function ($value): float {
            $value = str_replace(',', '', (string)$value);
            return is_numeric($value) ? (float)$value : 0.0;
        };
        $text = function (string $field) use ($r): string {
            return trim((string)($r[$field] ?? ''));
        };
        return [
            'invoice_no' => $text('invoice_no'),
            'invoice_date' => $text('invoice_date'),
            'gp_date' => $text('gp_date'),
            'exp_delivery_date' => $text('exp_delivery_date'),
            'ship_via' => $text('ship_via'),
            'trailer_nbr' => $text('trailer_nbr'),
            'dest_store_no' => $text('dest_store_no'),
            'ean_upc' => $text('ean_upc'),
            'quantity' => is_numeric($r['quantity'] ?? '') ? (int)$r['quantity'] : 0,
            'selling_price' => $number($r['selling_price'] ?? ''),
            'total_cost_ex_vat' => $number($r['total_cost_ex_vat'] ?? ''),
            'total_vat' => $number($r['total_vat'] ?? ''),
            'delivery_no' => $text('delivery_no'),
            'amount_kind' => $kind,
            'raw_json' => json_encode($r, JSON_UNESCAPED_UNICODE),
        ];
    }

    private static function signature(array $row): string
    {
        return implode('|', [
            $row['invoice_no'], $row['ean_upc'], (int)$row['quantity'], $row['dest_store_no'], round((float)$row['selling_price'], 4),
            round((float)$row['total_cost_ex_vat'], 4), round((float)$row['total_vat'], 4), $row['delivery_no'], $row['amount_kind'],
        ]);
    }
}
