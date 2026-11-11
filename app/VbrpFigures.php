<?php

namespace App;

use PDO;

/**
 * What the VBRP rows of a Job say per bill and per line, the third source of the Tax Invoice compare. Only the rows that
 * count are summed: Subtotal 3 (Excel CJ) below SKIP_FROM, or not stored (rows read before the column was kept). Bills
 * are keyed as the reconciler keys invoices (DashboardBuilder::invoiceKey), lines by that key and the barcode's digits.
 * A bill is covered when it has at least one such row.
 */
final class VbrpFigures
{
    /** A row whose Subtotal 3 is this or higher is not calculated at all (the user's rule) */
    public const SKIP_FROM = -1.0;

    /** @var array invoice key => ['rows' => int, 'cost' => sum of CK, 'vat' => sum of EM, 'lines' => barcodes listed] */
    private $bills;

    /** @var array invoice key => barcode => ['qty' => sum of H, 'price' => lowest CI or null] */
    private $lines;

    private function __construct(array $bills, array $lines)
    {
        $this->bills = $bills;
        $this->lines = $lines;
    }

    /**
     * The figures of the Job's rows; none when the VBRP tables do not exist yet
     */
    public static function forJob(PDO $db, int $jobId): self
    {
        try {
            $stmt = $db->prepare('SELECT invoice, ean_upc, quantity, selling_price, total_cost_ex_vat, total_vat, subtotal_3 FROM job_vbrp_rows WHERE job_id = :job_id ORDER BY id');
            $stmt->execute([':job_id' => $jobId]);
        } catch (\PDOException $e) {
            return new self([], []);
        }
        return self::fromRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array $rows rows with the fields VbrpStore keeps: invoice, ean_upc, quantity, selling_price,
     *                    total_cost_ex_vat, total_vat, subtotal_3
     */
    public static function fromRows(array $rows): self
    {
        $bills = [];
        $lines = [];
        foreach ($rows as $row) {
            $subtotal3 = $row['subtotal_3'] ?? null;
            $key = DashboardBuilder::invoiceKey((string)($row['invoice'] ?? ''));
            if (($subtotal3 !== null && (float)$subtotal3 >= self::SKIP_FROM) || $key === '') {
                continue;
            }
            if (!isset($bills[$key])) {
                $bills[$key] = ['rows' => 0, 'cost' => 0.0, 'vat' => 0.0, 'lines' => 0];
            }
            $bills[$key]['rows']++;
            $bills[$key]['cost'] += (float)($row['total_cost_ex_vat'] ?? 0);
            $bills[$key]['vat'] += (float)($row['total_vat'] ?? 0);

            $barcode = preg_replace('/[^0-9]/', '', (string)($row['ean_upc'] ?? ''));
            if ($barcode === '') {
                continue;
            }
            if (!isset($lines[$key][$barcode])) {
                $lines[$key][$barcode] = ['qty' => 0.0, 'price' => null];
            }
            $line = &$lines[$key][$barcode];
            $line['qty'] += (float)($row['quantity'] ?? 0);
            $price = $row['selling_price'] ?? null;
            if ($price !== null && ($line['price'] === null || (float)$price < $line['price'])) {
                $line['price'] = (float)$price;
            }
            unset($line);
        }
        foreach ($bills as $key => &$bill) {
            $bill['cost'] = round($bill['cost'], 2);
            $bill['vat'] = round($bill['vat'], 2);
            $bill['lines'] = count($lines[$key] ?? []);
        }
        unset($bill);
        foreach ($lines as &$byBarcode) {
            foreach ($byBarcode as &$line) {
                $line['qty'] = round($line['qty'], 2);
            }
            unset($line);
        }
        unset($byBarcode);
        return new self($bills, $lines);
    }

    public function isEmpty(): bool
    {
        return empty($this->bills);
    }

    public function covers(string $invoiceKey): bool
    {
        return isset($this->bills[$invoiceKey]);
    }

    /**
     * @return array|null ['rows', 'cost', 'vat', 'lines'] of a covered bill
     */
    public function bill(string $invoiceKey): ?array
    {
        return $this->bills[$invoiceKey] ?? null;
    }

    public function lists(string $invoiceKey, string $barcode): bool
    {
        return isset($this->lines[$invoiceKey][$barcode]);
    }

    /**
     * ['qty', 'price'] of a line; a barcode the bill does not list has 0 pieces and no price
     */
    public function line(string $invoiceKey, string $barcode): array
    {
        return $this->lines[$invoiceKey][$barcode] ?? ['qty' => 0.0, 'price' => null];
    }
}
