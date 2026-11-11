<?php

namespace App;

/**
 * The three sources of one compare line of a bill VBRP covers, PDF, ASN and VBRP, judged check by check (the user's
 * rule): a source is ok when it equals at least one other present source within the check's tolerance, and wrong when
 * it equals none. Two present sources that differ: the one later in AUTHORITY is wrong. Fewer than two: nothing is
 * judged. A source without a figure is na. It also words the PDF problems, the notes on ASN and VBRP, and row.sources.
 * The selling price is not a check (the user's call: the PDF prints none per line); VBRP's is only shown.
 */
final class SourceVerdict
{
    public const OK = 'ok';
    public const WRONG = 'wrong';
    public const NA = 'na';

    public const SOURCES = ['pdf' => 'PDF', 'asn' => 'ASN', 'vbrp' => 'VBRP'];

    /** Which of two differing sources stands, first to last */
    private const AUTHORITY = ['vbrp', 'asn', 'pdf'];

    /** check => its tolerance, its topic in a note and how its figures are written, in the order row.sources lists them */
    public const CHECKS = [
        'qty' => ['tolerance' => 0.0, 'topic' => 'จำนวน', 'format' => 'pieces'],
        'amount' => ['tolerance' => AsnReconciler::AMOUNT_TOLERANCE, 'topic' => 'ยอดก่อน VAT', 'format' => 'money'],
        'vat' => ['tolerance' => AsnReconciler::VAT_TOLERANCE, 'topic' => 'VAT', 'format' => 'baht'],
    ];

    /** The problem of a PDF a check judges wrong, with the figures of PDF, ASN and VBRP */
    private const PROBLEMS = [
        'qty' => 'จำนวนไม่ตรง (PDF: %s / ASN: %s / VBRP: %s)',
        'amount' => 'ยอดรวมไม่ตรง (PDF: %s / ASN: %s / VBRP: %s)',
        'vat' => 'VAT ไม่ตรง (PDF: %s / ASN: %s / VBRP: %s)',
    ];

    /** A source a check judges wrong. %1$s the source, %2$s the topic, %3$s its figure, %4$s the others that have one, with theirs */
    private const NOTE = '%1$s ไม่ตรง: %2$s %3$s (%4$s)';

    /**
     * The problem of an ASN a check judges wrong, which makes its bill wait for the ASN. %1$s the topic, %2$s the ASN's
     * figure, %3$s the others that have one, joined with " = " when they agree with each other
     */
    private const ASN_PROBLEM = 'ASN %1$s ไม่ตรง (ASN %2$s / %3$s)';

    /** A barcode the PDF of a covered bill prints with the pieces VBRP lists, which its ASN lines lack. %1$s barcode, %2$s pieces */
    public const ASN_LACKS = 'ASN ไม่มีบาร์โค้ด %1$s (%2$s ชิ้น) ที่ PDF และ VBRP มี';

    /** The note of an ASN line that neither the PDF nor the VBRP of its bill lists */
    public const ASN_ONLY = 'ASN มีบาร์โค้ดนี้แต่ PDF และ VBRP ไม่มี';

    /** @var array check => source => ?float */
    private $figures = [];

    /** @var array check => source => ok | wrong | na */
    private $verdicts = [];

    /** @var float|null */
    private $vbrpPrice;

    /**
     * @param array $figures check => ['pdf' => ?float, 'asn' => ?float, 'vbrp' => ?float] for every check of CHECKS;
     *                       null where the source has no figure
     * @param float|null $vbrpPrice VBRP's lowest Subtotal 2 of the line, null when it has none
     */
    public function __construct(array $figures, ?float $vbrpPrice)
    {
        $this->vbrpPrice = $vbrpPrice;
        foreach (self::CHECKS as $check => $rule) {
            $this->figures[$check] = $figures[$check];
            $this->verdicts[$check] = self::judge($figures[$check]['pdf'], $figures[$check]['asn'], $figures[$check]['vbrp'], $rule['tolerance']);
        }
    }

    /**
     * Each source pairwise against the others, so with the tolerance 100 / 101 / 102 are all ok
     *
     * @return array ['pdf' => ok|wrong|na, 'asn' => ..., 'vbrp' => ...]
     */
    public static function judge(?float $pdf, ?float $asn, ?float $vbrp, float $tolerance): array
    {
        $present = array_filter(['pdf' => $pdf, 'asn' => $asn, 'vbrp' => $vbrp], function ($value) {
            return $value !== null;
        });
        $verdict = ['pdf' => self::NA, 'asn' => self::NA, 'vbrp' => self::NA];
        if (count($present) < 2) {
            return $verdict;
        }
        foreach ($present as $source => $value) {
            $verdict[$source] = self::WRONG;
            foreach ($present as $other => $otherValue) {
                if ($other !== $source && self::agree($value, $otherValue, $tolerance)) {
                    $verdict[$source] = self::OK;
                }
            }
        }
        if (count($present) === 2 && !in_array(self::OK, $verdict, true)) {
            foreach (self::AUTHORITY as $source) {
                if (isset($present[$source])) {
                    $verdict[$source] = self::OK;
                    break;
                }
            }
        }
        return $verdict;
    }

    /** Compared in satang: 13,028.05 - 13,027.05 is a hair above 1.00 as a float */
    private static function agree(float $a, float $b, float $tolerance): bool
    {
        return round(abs($a - $b), 2) <= $tolerance;
    }

    /** A money figure that is not positive is no figure, as the two-source compare treats it */
    public static function positive(float $value): ?float
    {
        return $value > 0 ? $value : null;
    }

    public function pdfWrong(string $check): bool
    {
        return $this->verdicts[$check]['pdf'] === self::WRONG;
    }

    public function asnWrong(string $check): bool
    {
        return $this->verdicts[$check]['asn'] === self::WRONG;
    }

    /**
     * "ASN ยอดก่อน VAT ไม่ตรง (ASN 35,177.60 / PDF 20,456.74 = VBRP 20,456.74)": a source without a figure is left out
     */
    public function asnProblem(string $check): string
    {
        $figures = $this->figures[$check];
        $others = [];
        foreach (['pdf', 'vbrp'] as $source) {
            if ($figures[$source] !== null) {
                $others[] = self::SOURCES[$source] . ' ' . self::figure($check, $figures[$source]);
            }
        }
        $agreeing = count($others) === 2 && self::agree($figures['pdf'], $figures['vbrp'], self::CHECKS[$check]['tolerance']);
        return sprintf(self::ASN_PROBLEM, self::CHECKS[$check]['topic'], self::figure($check, $figures['asn']), implode($agreeing ? ' = ' : ' / ', $others));
    }

    public function problem(string $check): string
    {
        return vsprintf(self::PROBLEMS[$check], array_map(function ($value) use ($check) {
            return self::figure($check, $value);
        }, array_values($this->figures[$check])));
    }

    /**
     * One sentence per check that judges ASN or VBRP wrong, in the order of CHECKS, ASN before VBRP. None for a check
     * that judges the PDF wrong: its problem already names the three figures (the user's call), and none for a check
     * the line's problem already names
     *
     * @param array $instead check => the sentence that stands for that check's sentences
     * @param string[] $named the checks the line's problem names
     */
    public function notes(array $instead = [], array $named = []): array
    {
        $notes = [];
        foreach ($this->verdicts as $check => $verdict) {
            if ($verdict['pdf'] === self::WRONG || in_array($check, $named, true)) {
                continue;
            }
            foreach (['asn', 'vbrp'] as $source) {
                if ($verdict[$source] !== self::WRONG) {
                    continue;
                }
                if (isset($instead[$check])) {
                    $notes[] = $instead[$check];
                    break;
                }
                $others = [];
                foreach (self::SOURCES as $other => $name) {
                    if ($other !== $source && $this->figures[$check][$other] !== null) {
                        $others[] = $name . ': ' . self::figure($check, $this->figures[$check][$other]);
                    }
                }
                $notes[] = sprintf(self::NOTE, self::SOURCES[$source], self::CHECKS[$check]['topic'], self::figure($check, $this->figures[$check][$source]), implode(' / ', $others));
            }
        }
        return $notes;
    }

    /**
     * row.sources: the VBRP figures, the verdicts, each source over the line and the notes. A source is wrong over the
     * line when a check judges it so, else ok when one judges it ok, else na. The PDF is also wrong on a wrong line
     * (MISMATCH: store, barcodes, footer, a line it lacks) and is not wrong on a line that only waits for the fix
     * button: the plan has judged the rows as the fix leaves them, so its figures are right and its pages are what
     * wait. na only for a MATCH line with no PDF row and no PDF figure judged.
     */
    public function payload(string $status, bool $pdfRow, array $notes): array
    {
        $overall = [];
        foreach (array_keys(self::SOURCES) as $source) {
            $seen = array_column($this->verdicts, $source);
            $overall[$source] = in_array(self::WRONG, $seen, true) ? self::WRONG : (in_array(self::OK, $seen, true) ? self::OK : self::NA);
        }
        $overall['pdf'] = $status === BillStatus::MISMATCH || $overall['pdf'] === self::WRONG
            ? self::WRONG
            : ($status === BillStatus::PENDING_FIX || $pdfRow || $overall['pdf'] !== self::NA ? self::OK : self::NA);
        return [
            'vbrp' => array_combine(array_keys($this->figures), array_column($this->figures, 'vbrp')) + ['price' => $this->vbrpPrice],
            'verdict' => $this->verdicts,
            'overall' => $overall,
            'notes' => $notes,
        ];
    }

    public static function pieces(float $value): string
    {
        return (string)round($value, 2);
    }

    private static function figure(string $check, ?float $value): string
    {
        if ($value === null) {
            return '-';
        }
        $format = self::CHECKS[$check]['format'];
        if ($format === 'pieces') {
            return self::pieces($value) . ' ชิ้น';
        }
        return ($format === 'baht' ? '฿' : '') . number_format($value, 2);
    }
}
