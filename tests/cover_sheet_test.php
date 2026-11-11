<?php
// Run: php tests/cover_sheet_test.php
// The cover sheet (CoverSheet, StoreNames, CoverSheetPdf): grouping by store, sorting, blank cells for the shorter list, 19 rows
// a page, a page per store, totals on the last page, store names, and the PDF made from it. Pure functions and a PDF
// in the temp folder only; never touches data/ or myfile/.

require __DIR__ . '/../vendor/autoload.php';

use App\CoverSheet;
use App\CoverSheetPdf;
use App\StoreNames;

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

$invoices = [
    ['no' => '93248295-2', 'store' => '10103'],
    ['no' => '93248295-1', 'store' => '10103'],
    ['no' => '93240010', 'store' => '010102'],
    ['no' => '93240099', 'store' => ''],
];
$creditNotes = [
    ['no' => '93260357', 'store' => '10103'],
    ['no' => '93260257', 'store' => '10103'],
    ['no' => '93250905-2', 'store' => '10103'],
    ['no' => '93250905-1', 'store' => '10103'],
    ['no' => '93260001', 'store' => '10200'],
];
$groups = CoverSheet::group($invoices, $creditNotes);

check('stores are in natural order, leading zeros join the same store, documents without a store come last',
    array_column($groups, 'store_no') === ['010102', '10103', '10200', ''], json_encode(array_column($groups, 'store_no')));
check('each list of a store is sorted by number on its own, -1 and -2 are two rows',
    $groups[1]['invoices'] === ['93248295-1', '93248295-2'] && $groups[1]['credit_notes'] === ['93250905-1', '93250905-2', '93260257', '93260357']);

$groups[1]['store_name'] = 'CENTRAL SILOM COMPLEX';
$pages = CoverSheet::pages($groups);
$silom = $pages[1];
check('a store with more credit notes than invoices gets null (printed blank) in the invoice cells of the extra rows',
    count($silom['rows']) === 4 && $silom['rows'][1]['invoice'] === '93248295-2' && $silom['rows'][2]['invoice'] === null && $silom['rows'][3]['credit_note'] === '93260357');
check('a store with credit notes only has null in every invoice cell', $pages[2]['rows'][0]['invoice'] === null && $pages[2]['totals'] === ['invoices' => 0, 'credit_notes' => 1]);
check('a store with invoices only has null in every credit note cell', $pages[0]['rows'][0]['credit_note'] === null);
check('documents without a store print under ไม่ระบุสาขา', $pages[3]['store_no'] === CoverSheet::NO_STORE);
check('each store starts its own page', count($pages) === 4);

$many = [['store_no' => '10102', 'store_name' => 'CENTRAL CHIDLOM', 'invoices' => [], 'credit_notes' => []]];
for ($i = 1; $i <= 48; $i++) {
    $many[0]['invoices'][] = sprintf('9300%04d', $i);
}
for ($i = 1; $i <= 37; $i++) {
    $many[0]['credit_notes'][] = sprintf('9400%04d', $i);
}
$manyPages = CoverSheet::pages($many);
check('48 rows make pages of 19, 19 and 10', array_map(function ($p) { return count($p['rows']); }, $manyPages) === [19, 19, 10]);
check('row numbers go on across the pages of a store', $manyPages[1]['rows'][0]['seq'] === 20 && $manyPages[2]['rows'][9]['seq'] === 48);
check('only the last page of a store carries the totals, counted per side without the blank cells',
    $manyPages[0]['totals'] === null && $manyPages[1]['totals'] === null && $manyPages[2]['totals'] === ['invoices' => 48, 'credit_notes' => 37]);
check('rows past the credit notes have no credit note', $manyPages[1]['rows'][18]['credit_note'] === null && $manyPages[1]['rows'][17]['credit_note'] === '94000037');
check('a store with nothing makes no page', CoverSheet::pages([['store_no' => '1', 'store_name' => '', 'invoices' => [], 'credit_notes' => []]]) === []);

$names = new StoreNames();
check('the store names of the sample sheet are known', $names->name('10102') === 'CENTRAL CHIDLOM' && $names->name('22802') === 'ROBINSON CHANTHABURI');
check('a store number with leading zeros finds its name', $names->name('010102') === 'CENTRAL CHIDLOM');
$names->learnFromCreditNotes([['customer' => '5999-CENTRAL TEST STORE 19999', 'store_no' => '19999']]);
check('a credit note store line names a store the file does not know', $names->name('19999') === 'CENTRAL TEST STORE');
check('a store line of another store number names nothing', StoreNames::nameOnStoreLine('5002-CENTRAL PHUKET 10121', '10122') === null);

$target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cover_sheet_test_' . bin2hex(random_bytes(4)) . '.pdf';
(new CoverSheetPdf())->render(array_merge($pages, $manyPages), CoverSheet::DEFAULT_SETTINGS, $target);
$parsed = (new \Smalot\PdfParser\Parser())->parseFile($target);
$pdfPages = $parsed->getPages();
check('the PDF has one page per cover page', count($pdfPages) === count($pages) + count($manyPages), (string)count($pdfPages));
$first = $pdfPages[1]->getText();
check('the PDF writes the documents of both lists', strpos($first, '93250905-1') !== false && strpos($first, '93248295-2') !== false);
$blankTarget = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cover_sheet_blank_' . bin2hex(random_bytes(4)) . '.pdf';
(new CoverSheetPdf())->render([$pages[2]], ['contact' => '', 'mobile' => '', 'case_label' => '', 'date' => ''], $blankTarget);
$blankText = (new \Smalot\PdfParser\Parser())->parseFile($blankTarget)->getPages()[0]->getText();
check('a cell without a document stays blank: a credit note only page prints no "-" and no invoice quantity',
    strpos($blankText, '93260001') !== false && strpos($blankText, '-') === false && substr_count($blankText, '1') === substr_count('1 10200 93260001 1 0 1', '1'), json_encode($blankText));
@unlink($blankTarget);
check('the PDF writes the totals on the last page of a store', strpos($pdfPages[count($pdfPages) - 1]->getText(), '37') !== false);
@unlink($target);

echo $failures === 0 ? "\nAll cover sheet checks passed\n" : "\n$failures cover sheet check(s) failed\n";
exit($failures === 0 ? 0 : 1);
