<?php
// Run: php tests/credit_note_reader_test.php
// CreditNoteReader on the real credit notes of tests/fixtures/cn: every file must keep the extractor's answer and pass
// its own arithmetic, and twelve files must give the item lines and totals printed on them. Reads files only.

const CN_DIR = __DIR__ . '/fixtures/cn/';
const CN_FILE_COUNT = 545;

$files = glob(CN_DIR . '*.pdf') ?: [];
if (count($files) !== CN_FILE_COUNT) {
    fwrite(STDERR, 'SKIP: tests/fixtures/cn must hold the ' . CN_FILE_COUNT . ' credit note samples, it holds ' . count($files) . "\n");
    exit(2);
}
sort($files);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require __DIR__ . '/../vendor/autoload.php';

use App\CreditNoteReader;
use App\PdfExtractor;

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function cents(float $amount): int
{
    return (int)round($amount * 100);
}

/**
 * Where the document's printed numbers disagree with each other, worked out here from the requirement, not by the reader
 */
function arithmeticGaps(array $doc): array
{
    $gaps = [];
    $itemCents = 0;
    $units = 0;
    foreach ($doc['items'] as $item) {
        if (cents($item['units'] * $item['unit_price']) !== cents($item['amount'])) {
            $gaps[] = 'units x price != amount';
        }
        $itemCents += cents($item['amount']);
        $units += $item['units'];
    }
    if ($itemCents - cents((float)$doc['promo_discount']) !== cents((float)$doc['total_amount'])) {
        $gaps[] = 'items - promotion != Total Amount';
    }
    if ($units !== $doc['shipped_units']) {
        $gaps[] = 'units != TOTAL SHIPPED';
    }
    if (abs(cents((float)$doc['excl_vat']) + cents((float)$doc['vat']) - cents((float)$doc['incl_vat'])) > 3) {
        $gaps[] = 'excl + VAT != incl beyond 0.03';
    }
    if (cents((float)$doc['total_amount']) - cents((float)$doc['total_discount']) !== cents((float)$doc['after_discount'])) {
        $gaps[] = 'Total Amount - Total Discount != After Discount';
    }
    if (cents((float)$doc['after_discount']) !== cents((float)$doc['incl_vat'])) {
        $gaps[] = 'After Discount != Include VAT';
    }
    return $gaps;
}

$legacyKeys = ['doc_no', 'doc_date', 'customer', 'format_type', 'filename', 'pages', 'orig_amount', 'corr_amount', 'diff_amount', 'vat_amount', 'amount'];
$keys = array_merge($legacyKeys, ['doc_date_iso', 'store_no', 'items', 'shipped_units', 'promo_discount', 'total_amount', 'total_discount',
    'after_discount', 'excl_vat', 'vat', 'incl_vat', 'gp_percent', 'read_ok', 'read_note']);
$itemKeys = ['barcode', 'product_code', 'description', 'units', 'unit_price', 'amount'];

$reader = new CreditNoteReader();
$extractor = new PdfExtractor();
$read = [];
$legacyDiffers = [];
$shapeDiffers = [];
$started = microtime(true);
foreach ($files as $file) {
    $docs = $reader->read($file);
    $read[basename($file)] = $docs;
    $legacy = array_map(function (array $doc) use ($legacyKeys) {
        return array_intersect_key($doc, array_flip($legacyKeys));
    }, $docs);
    if ($legacy !== $extractor->extractCnDocuments($file)) {
        $legacyDiffers[] = basename($file);
    }
    foreach ($docs as $doc) {
        $itemsShaped = true;
        foreach ($doc['items'] as $item) {
            $itemsShaped = $itemsShaped && array_keys($item) === $itemKeys && preg_match('/^\d{13}$/', $item['barcode'])
                && is_int($item['units']) && is_float($item['unit_price']) && is_float($item['amount']);
        }
        if (array_keys($doc) !== $keys || !$itemsShaped || !is_bool($doc['read_ok']) || !is_string($doc['read_note'])) {
            $shapeDiffers[] = basename($file) . ' ' . $doc['doc_no'];
        }
    }
}
$seconds = microtime(true) - $started;

$documents = [];
foreach ($read as $docs) {
    foreach ($docs as $doc) {
        $documents[] = $doc;
    }
}
$byFormat = [];
$notOk = [];
$okButGaps = [];
$gapsButOk = [];
foreach ($documents as $doc) {
    $byFormat[$doc['format_type']] = ($byFormat[$doc['format_type']] ?? 0) + 1;
    if (!$doc['read_ok']) {
        $notOk[] = $doc['doc_no'] . ' (' . $doc['format_type'] . ': ' . $doc['read_note'] . ')';
        continue;
    }
    $gaps = arithmeticGaps($doc);
    if ($gaps) {
        $okButGaps[] = $doc['doc_no'] . ': ' . implode(', ', $gaps);
    }
}
ksort($byFormat);

check(
    'the ' . CN_FILE_COUNT . ' sample files hold 557 credit note documents: 72 Standard CN, 484 Variance / Refund, 1 Attachment Sheet',
    count($documents) === 557 && $byFormat === ['Attachment Sheet' => 1, 'Standard CN' => 72, 'Variance / Refund' => 484],
    json_encode($byFormat)
);
check(
    'for every file the reader answers what PdfExtractor::extractCnDocuments answers, value for value, under the same keys',
    $legacyDiffers === [],
    implode('; ', array_slice($legacyDiffers, 0, 5))
);
check(
    'every document has exactly the extractor keys then doc_date_iso, store_no, items, the totals, gp_percent, read_ok, read_note; every item a 13-digit barcode and numbers',
    $shapeDiffers === [],
    implode('; ', array_slice($shapeDiffers, 0, 5))
);
check(
    'every document except the Attachment Sheet is read_ok: item amounts minus promotion = Total Amount, item units = TOTAL SHIPPED, Excluding VAT + VAT = Include VAT within 0.03',
    $notOk === ['93260445 (Attachment Sheet: no item lines; not printed or not read: shipped_units, total_amount, total_discount, after_discount, excl_vat, vat, incl_vat)'],
    implode('; ', array_slice($notOk, 0, 5))
);
check('no read_ok document breaks its own arithmetic (worked out by this test)', $okButGaps === [], implode('; ', array_slice($okButGaps, 0, 5)));
printf("      read %d files with the reader and the extractor in %.1f s\n", CN_FILE_COUNT, $seconds);

/**
 * An expected document: the fields given must equal the reader's; 'items' lists every line as
 * [barcode, product code, units, unit price, amount] (a sixth value is the description), 'lines' => [count, [index => line]]
 * lists some
 */
function expectDoc(string $no, array $fields): array
{
    return ['doc_no' => $no] + $fields;
}

function totals(?int $shipped, ?float $promo, ?float $total, ?float $discount, ?float $after, ?float $excl, ?float $vat, ?float $incl, ?float $gp): array
{
    return [
        'shipped_units' => $shipped, 'promo_discount' => $promo, 'total_amount' => $total, 'total_discount' => $discount,
        'after_discount' => $after, 'excl_vat' => $excl, 'vat' => $vat, 'incl_vat' => $incl, 'gp_percent' => $gp,
    ];
}

function lineMatches(array $item, array $line): bool
{
    $ok = $item['barcode'] === $line[0] && $item['product_code'] === $line[1] && $item['units'] === $line[2]
        && $item['unit_price'] === $line[3] && $item['amount'] === $line[4];
    return $ok && (!isset($line[5]) || $item['description'] === $line[5]);
}

// Read off each page with pdftotext -layout; amounts as printed
$expected = [
    '5048_93251044-1_DN.issue.at_20261016_5048-ROBINSON BOWIN 20186.pdf' => [
        expectDoc('93251044-1', ['doc_date_iso' => '2026-04-09', 'store_no' => '20186', 'read_ok' => true,
            'items' => [['5401221448674', '00505-31683332', 1, 2190.00, 2190.00]]]
            + totals(1, 438.00, 1752.00, 490.56, 1261.44, 1178.91, 82.53, 1261.44, 28.0)),
    ],
    '5002_93250403-1_DN.issue.at_20261011_5002-CENTRAL PHUKET 10121.pdf' => [
        expectDoc('93250403-1', ['doc_date_iso' => '2026-04-06', 'store_no' => '10121', 'read_ok' => true, 'items' => [
            ['6910704905126', '00505-15503132', 1, 2590.00, 2590.00],
            ['5401221425163', '00505-34183132', 1, 3490.00, 3490.00],
            ['6910704905126', '00505-15503132', 1, 2590.00, 2590.00],
        ]] + totals(3, 1734.00, 6936.00, 1942.08, 4993.92, 4667.23, 326.69, 4993.92, 28.0)),
    ],
    // printed Excluding VAT + VAT is 3 satang below Include VAT
    '5056_93251025-2_DN.issue.at_20261011_5056-CENTRAL KHONKAEN 10166.pdf' => [
        expectDoc('93251025-2', ['doc_date_iso' => '2026-04-10', 'store_no' => '10166', 'read_ok' => true, 'items' => [
            ['5401221400719', '001PZ-00142830', 1, 3190.00, 3190.00],
            ['5401221400719', '001PZ-00142830', 1, 3190.00, 3190.00],
        ]] + totals(2, 1276.00, 5104.00, 1020.80, 4083.20, 3816.05, 267.12, 4083.20, 20.0)),
    ],
    // the store line prints no store number
    '5152_93249892-1_DN.issue.at_20261011_5152-CENTRAL NAKORN  SAWAN.pdf' => [
        expectDoc('93249892-1', ['doc_date_iso' => '2026-04-28', 'store_no' => null, 'read_ok' => true,
            'items' => [['5401221360662', '21976-0187M', 1, 2190.00, 2190.00]]]
            + totals(1, 438.00, 1752.00, 490.56, 1261.44, 1178.91, 82.53, 1261.44, 28.0)),
    ],
    // the store number is printed 020123, the ASN says 20123
    '5052_93251363-1_DN.issue.at_20261016_5052-ROBINSON CHIANGRAI 020123.pdf' => [
        expectDoc('93251363-1', ['doc_date_iso' => '2026-04-09', 'store_no' => '20123', 'read_ok' => true, 'items' => [
            ['5401221420885', 'A7210-0065L', 1, 2190.00, 2190.00],
            ['5401221362079', 'A7210-0053M', 1, 2190.00, 2190.00],
            ['5401132603513', '29037-00623132', 1, 2890.00, 2890.00],
        ]] + totals(3, 1454.00, 5816.00, 1628.48, 4187.52, 3913.56, 273.96, 4187.52, 28.0)),
    ],
    // six pages, no Promotion Discount; descriptions run over two and three rows and over a page break
    'RM Document-Customer Billing(PDF)-0093240256.pdf' => [
        expectDoc('93240256', ['doc_date_iso' => '2026-04-27', 'store_no' => '10110', 'read_ok' => true, 'lines' => [52, [
            0 => ['5401221157699', '00501-3741/3332', 1, 3490.00, 3490.00, "501® LEVI'S®ORIGINAL BRIGHTSIDE- Levi's"],
            9 => ['5401221419117', 'A0637-0195/S-', 1, 1190.00, 1190.00, 'RED TAB VINTAGE TEE GRUNGE WASH FUNGI- L'],
            13 => ['5401221119802', '001GU-0012/3412', 1, 1890.00, 1890.00, '478 BAGGY CARGO SHORTS NEXT ONE DOWN SHO'],
            51 => ['5401221463134', '0073B-0001/3029', 1, 3190.00, 3190.00, "CINCH WIDE LEG PASS THE PARCEL- Levi's -"],
        ]]] + totals(52, null, 142180.00, 39810.40, 102369.60, 95672.58, 6697.02, 102369.60, 28.0)),
    ],
    'RM Document-Customer Billing(PDF)-0093240265.pdf' => [
        expectDoc('93240265', ['doc_date_iso' => '2026-04-27', 'store_no' => null, 'read_ok' => true, 'lines' => [54, [
            0 => ['5401221398238', '0062J-0000/XS-', 1, 2290.00, 2290.00],
            53 => ['5401221402287', '003QV-0004/XL-', 1, 1190.00, 1190.00],
        ]]] + totals(54, null, 120160.00, 33644.80, 86515.20, 80855.43, 5659.77, 86515.20, 28.0)),
    ],
    // two documents in one file, header PRODUCT/SZ
    'RM Document-Customer Billing(PDF)-0093247387 3.pdf' => [
        expectDoc('93247387-1', ['doc_date_iso' => '2026-04-06', 'store_no' => '20186', 'read_ok' => true, 'items' => [
            ['5400973794602', '39864-0058/3410', 1, 1890.00, 1890.00],
            ['5401221426603', '16143-2505/M', 1, 1190.00, 1190.00],
            ['5401221399105', '56605-0306/L', 1, 790.00, 790.00],
            ['5401221409569', '005H5-0000/L', 1, 2190.00, 2190.00],
        ]] + totals(4, 1212.00, 4848.00, 1357.44, 3490.56, 3262.21, 228.35, 3490.56, 28.0)),
        expectDoc('93247387-2', ['doc_date_iso' => '2026-04-06', 'store_no' => '20186', 'read_ok' => true,
            'items' => [['5401180597383', 'A7221-0028/3332', 1, 3490.00, 3490.00]]]
            + totals(1, 698.00, 2792.00, 558.40, 2233.60, 2087.47, 146.13, 2233.60, 20.0)),
    ],
    // three documents in one file, three GP percentages
    'RM Document-Customer Billing(PDF)-0093249119 2.pdf' => [
        expectDoc('93249119-1', ['doc_date_iso' => '2026-04-19', 'store_no' => '10160', 'read_ok' => true, 'items' => [
            ['6902653957585', '29507-0138/3132', 1, 2890.00, 2890.00],
            ['5400922754510', '85226-0049/3132', 1, 2390.00, 2390.00],
        ]] + totals(2, 1056.00, 4224.00, 1182.72, 3041.28, 2842.31, 198.97, 3041.28, 28.0)),
        expectDoc('93249119-2', ['doc_date_iso' => '2026-04-19', 'store_no' => '10160', 'read_ok' => true,
            'items' => [['5401221084346', '00505-2913/3032', 1, 2890.00, 2890.00]]]
            + totals(1, 867.00, 2023.00, 485.52, 1537.48, 1436.90, 100.58, 1537.48, 24.0)),
        expectDoc('93249119-3', ['doc_date_iso' => '2026-04-19', 'store_no' => '10160', 'read_ok' => true, 'items' => [
            ['5401180924523', '19631-0236/2830', 1, 3190.00, 3190.00],
            ['5401221066380', '29037-0092/3132', 1, 2890.00, 2890.00],
            ['5401180517336', '72625-0116/L', 1, 2090.00, 2090.00],
            ['5401180514960', '19887-0360/2427', 1, 2890.00, 2890.00],
        ]] + totals(4, 5530.00, 5530.00, 1106.00, 4424.00, 4134.58, 289.42, 4424.00, 20.0)),
    ],
    // the totals block is drawn twice on the page
    'RM Document-Customer Billing(PDF)-0093248365 2.pdf' => [
        expectDoc('93248365-1', ['doc_date_iso' => '2026-04-12', 'store_no' => '10143', 'read_ok' => true,
            'items' => [['5401039875297', '19631-0133/2730', 1, 2390.00, 2390.00, "314 SHAPING STRAIGHT LAPIS LOFT- Levi's"]]]
            + totals(1, 478.00, 1912.00, 535.36, 1376.64, 1286.58, 90.06, 1376.64, 28.0)),
    ],
    // a refund of 0.00 on the left block, a full right-hand block
    'RM Document-Customer Billing(PDF)-0093260255.pdf' => [
        expectDoc('93260255', ['doc_date_iso' => '2026-04-14', 'store_no' => '10107', 'read_ok' => true,
            'items' => [['6901462632683', '19627-0001/3230', 1, 2590.00, 2590.00, "312 SHAPING SLIM DARKEST SKY- Levi's -"]]]
            + totals(1, null, 2590.00, 725.20, 1864.80, 1742.81, 121.99, 1864.80, 28.0)),
    ],
    // lines of product codes and invoice serials without barcodes, no right-hand block
    'RM Document-Customer Billing(PDF)-0093260445.pdf' => [
        expectDoc('93260445', ['doc_date_iso' => '2026-04-25', 'store_no' => null, 'read_ok' => false, 'items' => []]
            + totals(null, null, null, null, null, null, null, null, null)),
    ],
];

foreach ($expected as $file => $docs) {
    $got = $read[$file] ?? [];
    $wrong = [];
    if (array_map('strval', array_column($got, 'doc_no')) !== array_column($docs, 'doc_no')) {
        $wrong[] = 'documents ' . json_encode(array_column($got, 'doc_no'));
    }
    foreach ($docs as $i => $want) {
        $doc = $got[$i] ?? null;
        if ($doc === null) {
            continue;
        }
        foreach (array_diff_key($want, ['items' => 1, 'lines' => 1, 'doc_no' => 1]) as $field => $value) {
            if ($doc[$field] !== $value) {
                $wrong[] = "{$want['doc_no']} $field " . json_encode($doc[$field]) . ' expected ' . json_encode($value);
            }
        }
        if (isset($want['items'])) {
            $same = count($doc['items']) === count($want['items']);
            foreach ($want['items'] as $n => $line) {
                $same = $same && lineMatches($doc['items'][$n], $line);
            }
            if (!$same) {
                $wrong[] = "{$want['doc_no']} items " . json_encode($doc['items'], JSON_UNESCAPED_UNICODE);
            }
        }
        if (isset($want['lines'])) {
            list($count, $lines) = $want['lines'];
            if (count($doc['items']) !== $count) {
                $wrong[] = "{$want['doc_no']} " . count($doc['items']) . " item lines, expected $count";
            }
            foreach ($lines as $n => $line) {
                if (!isset($doc['items'][$n]) || !lineMatches($doc['items'][$n], $line)) {
                    $wrong[] = "{$want['doc_no']} line " . ($n + 1) . ' ' . json_encode($doc['items'][$n] ?? null, JSON_UNESCAPED_UNICODE);
                }
            }
        }
    }
    check("$file gives the documents, item lines and totals printed on it", $wrong === [], implode('; ', array_slice($wrong, 0, 4)));
}

$notCn = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cn_reader_test_' . bin2hex(random_bytes(4)) . '.pdf';
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 10, 'Delivery order 12345');
$pdf->Output('F', $notCn);
$answer = $reader->read($notCn);
unlink($notCn);
check('a PDF that names no credit note gives no document, as the extractor answers', $answer === []);

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
