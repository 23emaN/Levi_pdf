<?php
// Run: php tests/portal_test.php
// The Job portal (job.php), the credit note page (credit_note.php) and the links into them, rendered through the
// PHP CLI. Runs against a throwaway root (PDF_READER_ROOT), never touches data/ or myfile/.

$mode = $argv[1] ?? '';

if ($mode === '--api') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = ['action' => $request['action']] + $request['get'];
    $_POST = $request['post'];
    require __DIR__ . '/../api.php';
    exit;
}

if ($mode === '--page') {
    $request = json_decode(file_get_contents($argv[2]), true);
    $_GET = $request['get'];
    require __DIR__ . '/../' . $request['page'];
    exit;
}

const CN_SAMPLES = ['5002_93250403-1_DN.issue.at_20261011_5002-CENTRAL PHUKET 10121.pdf', '5013_93252398-1_DN.issue.at_20261011_5013-CENTRAL BANGNA 10116.pdf'];

if ($mode !== '--run') {
    foreach (CN_SAMPLES as $name) {
        if (!is_file(__DIR__ . '/fixtures/cn/' . $name)) {
            fwrite(STDERR, "SKIP: credit note sample not found: tests/fixtures/cn/$name\n");
            exit(2);
        }
    }
    if (!glob(__DIR__ . '/fixtures/ASN S4 *.xlsx')) {
        fwrite(STDERR, "SKIP: the ASN workbook tests/fixtures/ASN S4 ... .xlsx is not found\n");
        exit(2);
    }
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_reader_portal_' . bin2hex(random_bytes(4));
    mkdir($root . '/data', 0777, true);
    mkdir($root . '/myfile', 0777, true);
    putenv('PDF_READER_ROOT=' . $root);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run', $code);
    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($tree as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
    exit($code);
}

require __DIR__ . '/../vendor/autoload.php';

use App\JobManager;

$jm = new JobManager();
$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function runSelf(string $mode, array $request): string
{
    $requestFile = tempnam(sys_get_temp_dir(), 'portal_req_');
    file_put_contents($requestFile, json_encode($request));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " $mode " . escapeshellarg($requestFile));
    unlink($requestFile);
    return $out;
}

function api(string $action, array $post = [], array $get = []): array
{
    return json_decode(runSelf('--api', ['action' => $action, 'post' => $post, 'get' => $get]), true) ?: [];
}

function page(string $file, array $get): string
{
    return runSelf('--page', ['page' => $file, 'get' => $get]);
}

/**
 * Runs a node script of tests/ on the given texts (each written to a temp file) and returns its JSON, or null
 * when node is not on PATH
 */
function node(string $script, array $texts): ?array
{
    if (trim((string)shell_exec('node --version')) === '') {
        return null;
    }
    $files = [];
    foreach ($texts as $text) {
        $files[] = $file = tempnam(sys_get_temp_dir(), 'portal_node_');
        file_put_contents($file, $text);
    }
    $out = (string)shell_exec('node ' . implode(' ', array_map('escapeshellarg', array_merge([__DIR__ . '/' . $script], $files))));
    array_map('unlink', $files);
    return json_decode($out, true) ?: [];
}

function plainText(string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

function elementIds(string $html): array
{
    preg_match_all('/\bid="([^"]+)"/', $html, $m);
    return array_values(array_unique($m[1]));
}

function localScripts(string $html): array
{
    preg_match_all('/<script src="(assets\/[^"?]+)/', $html, $m);
    return $m[1];
}

// Names a classic script puts in the page's global scope: declarations that start a line at column 0
function topLevelNames(string $js): array
{
    preg_match_all('/^(?:const|let|var|function|async function|class)\s+([A-Za-z_$][\w$]*)/m', $js, $m);
    return $m[1];
}

function pageScriptNames(string $html): array
{
    $names = [];
    foreach (localScripts($html) as $src) {
        foreach (topLevelNames((string)file_get_contents(__DIR__ . '/../' . $src)) as $name) {
            $names[$name][] = $src;
        }
    }
    preg_match_all('/<script>(.*?)<\/script>/s', $html, $inline);
    foreach ($inline[1] as $js) {
        foreach (topLevelNames(preg_replace('/^\s+/m', '', $js)) as $name) {
            $names[$name][] = 'inline';
        }
    }
    return $names;
}

$job = $jm->createJob('Portal <A&B>');
$jobId = (int)$job['id'];

$item = function (string $invoice, string $barcode) {
    return ['page_no' => 1, 'tax_invoice' => $invoice, 'barcode' => $barcode, 'units' => 1, 'unit_price' => '10.00', 'amount' => '10.00'];
};
$entries = [
    ['filename' => 'a.pdf', 'storedPath' => '', 'items' => [$item('9700001-1', '8850000000001'), $item('9700001-1', '8850000000002'), $item('9700001-1', '8850000000003')]],
    ['filename' => 'b.pdf', 'storedPath' => '', 'items' => [$item('9700002-1', '8850000000004'), $item('9700002-1', '8850000000005')]],
];
$jm->recordExtractedBatch($jobId, $entries, true);
$asnRow = function (string $inv, string $ean) {
    return ['invoice_no' => $inv, 'ean_upc' => $ean, 'quantity' => 1, 'dest_store_no' => '', 'selling_price' => 1, 'total_cost_ex_vat' => 1, 'total_vat' => 0];
};
$jm->saveAsnRecords($jobId, 'asn one.xlsx [s]', [$asnRow('9700001-1', '8850000000001')]);
$jm->saveAsnRecords($jobId, 'asn two.xlsx [s]', [$asnRow('9700002-1', '8850000000004')]);

$cnUpload = $jm->getJobCnUploadDir($job);
foreach (CN_SAMPLES as $name) {
    copy(__DIR__ . '/fixtures/cn/' . $name, $cnUpload . '/' . $name);
}
$extract = api('job_cn_extract_batch', ['job_id' => $jobId, 'files' => json_encode(CN_SAMPLES)]);
check(
    'two one-page credit note PDFs are read into the Job through the API, one credit note each',
    ($extract['status'] ?? '') === 'success' && ($extract['documents_count'] ?? null) === 2,
    json_encode($extract, JSON_UNESCAPED_UNICODE)
);
$cnAsn = function (string $doc, string $ean, int $qty) {
    return ['invoice_no' => $doc, 'invoice_date' => '2026-04-06', 'dest_store_no' => '10121', 'ean_upc' => $ean, 'quantity' => $qty,
        'selling_price' => 1000, 'total_cost_ex_vat' => 0, 'total_vat' => 0, 'delivery_no' => '', 'amount_kind' => 'retail'];
};
$asnImport = api('job_cn_import_asn', ['job_id' => $jobId, 'asn_filename' => 'ASN test.xlsx / CDS ZG2T (ZCR) done', 'append' => 1, 'reconcile' => 0,
    'records' => json_encode([$cnAsn('93250403-1', '5401221466098', 1), $cnAsn('93250403-1', '5401221466099', 2)])]);
check(
    'one credit note sheet of two lines is imported into the Job through the API',
    ($asnImport['status'] ?? '') === 'success' && ($asnImport['asn_counts'] ?? null) === ['files' => 1, 'rows' => 2],
    json_encode($asnImport['asn_counts'] ?? $asnImport, JSON_UNESCAPED_UNICODE)
);

$portal = page('job.php', ['job_id' => $jobId]);
$card = function (string $key) use ($portal): array {
    if (!preg_match('/<a class="portal-card" id="portal-' . $key . '" href="([^"]*)"[^>]*>(.*?)<\/a>/s', $portal, $m)) {
        return ['href' => null, 'title' => '', 'pills' => []];
    }
    preg_match('/<span class="portal-title">(.*?)<\/span>/s', $m[2], $title);
    preg_match_all('/<span class="portal-pill">(.*?)<\/span>/s', $m[2], $pills);
    return ['href' => html_entity_decode($m[1]), 'title' => plainText($title[1] ?? ''), 'pills' => array_map('plainText', $pills[1])];
};
$taxCard = $card('tax-invoice');
$cnCard = $card('credit-note');
$coverCard = $card('cover-sheet');
check(
    'the portal of a Job offers three cards, TAX INVOICE to workspace.php, CREDIT NOTE to credit_note.php and ใบปะหน้า to cover_sheet.php, for this Job, and no VBRP card (the user no longer uses vbrp.php; VBRP is read in tab 2 of workspace.php)',
    $taxCard['href'] === "workspace.php?job_id=$jobId" && $taxCard['title'] === 'TAX INVOICE'
        && $cnCard['href'] === "credit_note.php?job_id=$jobId" && $cnCard['title'] === 'CREDIT NOTE'
        && $coverCard['href'] === "cover_sheet.php?job_id=$jobId" && $coverCard['title'] === 'ใบปะหน้า'
        && substr_count($portal, 'class="portal-card"') === 3 && strpos($portal, 'vbrp.php') === false,
    json_encode([$taxCard['href'], $taxCard['title'], $cnCard['href'], $cnCard['title'], $coverCard['href'], substr_count($portal, 'class="portal-card"')], JSON_UNESCAPED_UNICODE)
);
check(
    'the TAX INVOICE card counts the PDF files, rows and ASN files the status bar of workspace.php shows',
    $taxCard['pills'] === ['PDF 2 ไฟล์', 'รายการ 5 รายการ', 'ASN 2 ไฟล์'],
    json_encode($taxCard['pills'], JSON_UNESCAPED_UNICODE)
);
check(
    'the CREDIT NOTE card counts the credit note PDF files and documents read into the Job and its ASN sheets',
    $cnCard['pills'] === ['PDF 2 ไฟล์', 'ใบลดหนี้ 2 ใบ', 'ASN 1 ชีต'],
    json_encode($cnCard['pills'], JSON_UNESCAPED_UNICODE)
);
check(
    'the portal shows the Job name escaped and leads back to the Job list',
    strpos($portal, 'Portal &lt;A&amp;B&gt;') !== false && strpos($portal, 'Portal <A&B>') === false
        && preg_match('/<div class="app-brand">\s*<a href="index.php"/', $portal) === 1
);

$freshJob = $jm->createJob('Fresh');
$freshCard = page('job.php', ['job_id' => $freshJob['id']]);
check(
    'a Job with nothing read yet shows zero in every pill of both cards',
    substr_count($freshCard, '<strong class="tabular-num">0</strong>') === 6,
    (string)substr_count($freshCard, '<strong class="tabular-num">0</strong>')
);

$goneJob = $jm->createJob('Gone');
$goneFolder = $jm->getJobFolderDir($goneJob, false);
rename($goneFolder, $goneFolder . '_moved');
$unknown = [];
foreach (['no id' => [], 'zero' => ['job_id' => 0], 'unknown id' => ['job_id' => 999999], 'folder gone' => ['job_id' => $goneJob['id']]] as $label => $get) {
    $workspace = page('workspace.php', $get);
    foreach (['job.php', 'credit_note.php', 'vbrp.php'] as $file) {
        $out = page($file, $get);
        if ($out !== $workspace) {
            $unknown[] = "$file $label: " . substr($out, 0, 80);
        }
    }
}
rename($goneFolder . '_moved', $goneFolder);
check(
    'an unknown Job id, a missing one and a Job whose folder is gone get the same answer from the portal and the credit note page as from workspace.php (redirect to the list, or "not found" with a link back)',
    $unknown === [] && strpos(page('job.php', ['job_id' => 999999]), "<a href='index.php'>") !== false,
    json_encode($unknown, JSON_UNESCAPED_UNICODE)
);

$workspace = page('workspace.php', ['job_id' => $jobId]);
check(
    'the back arrow of workspace.php leads to the portal of its Job, and its link to the legacy credit note page stays',
    preg_match('/<div class="app-brand">\s*<a href="job.php\?job_id=' . $jobId . '"[^>]*title="กลับหน้าเลือกงานของ Job"/', $workspace) === 1
        && strpos($workspace, '<a href="read_cn_error01.php"') !== false
);

$cnPage = page('credit_note.php', ['job_id' => $jobId]);

/**
 * The header texts and data-col keys of a table of a rendered page
 */
function tableHead(string $html, string $tableId): array
{
    preg_match('/id="' . $tableId . '">\s*<thead>(.*?)<\/thead>/s', $html, $head);
    preg_match_all('/<th\b([^>]*)>(.*?)<\/th>/s', $head[1] ?? '', $ths);
    preg_match_all('/data-col="([^"]*)"/', implode(' ', $ths[1]), $cols);
    return ['labels' => array_map('plainText', $ths[2]), 'keys' => $cols[1], 'attributes' => $ths[1]];
}

$cnTables = [
    'credit-note-table' => ['#', 'File Name', 'Pages', 'Credit Note No.', 'Date', 'Customer', 'Type', 'Original Amt', 'Corrected Amt', 'Difference', 'VAT', 'Amount', 'Amt Excl VAT', 'VAT (7%)', 'Amt Incl VAT'],
    'credit-note-asn-table' => ['#', 'Status', 'Credit Note No.', 'Source File', 'Barcode / EAN', 'Description', 'Store (PDF)', 'Store (ASN)', 'Qty (PDF)', 'Qty (ASN)', 'Amount (PDF)', 'Amount (ASN)', 'VAT (PDF / ASN)', 'Remark'],
    'credit-note-dash-table' => ['#', 'Dest Store No.', 'Credit Note No.', 'Source File', 'Total Qty', 'Amount', 'Pages', 'Status', 'Remark'],
];
$headers = tableHead($cnPage, 'credit-note-table')['labels'];
$headFaults = [];
$keyFaults = [];
foreach ($cnTables as $tableId => $expected) {
    $head = tableHead($cnPage, $tableId);
    if ($head['labels'] !== $expected) {
        $headFaults[$tableId] = $head['labels'];
    }
    if (count($head['keys']) !== count($expected) || count(array_unique($head['keys'])) !== count($expected) || in_array('', $head['keys'], true)) {
        $keyFaults[$tableId] = $head['keys'];
    }
}
check(
    'the three credit note tables have their English headers in order: the documents (the right-hand totals after Amount), the compare grid and the overview',
    $headFaults === [],
    json_encode($headFaults)
);
check(
    'every header of the three credit note tables names its column (data-col) with its own key, so sort and column colours find it',
    $keyFaults === [],
    json_encode($keyFaults)
);
$amountHeads = array_filter(tableHead($cnPage, 'credit-note-asn-table')['attributes'], function ($attributes) {
    return strpos($attributes, 'data-col="pdf_amount"') !== false || strpos($attributes, 'data-col="asn_amount"') !== false;
});
check(
    'the two amount headers of the compare grid explain both bases in their tooltip, and the overview opens sorted by store',
    count($amountHeads) === 2 && array_reduce($amountHeads, function ($ok, $attributes) {
        return $ok && strpos($attributes, 'Total Cost Ex VAT') !== false && strpos($attributes, 'Quantity × Unit Ret') !== false
            && strpos($attributes, 'Total Amount') !== false;
    }, true)
        && preg_match('/<th data-col="store_no" data-type="string" class="sorted"[^>]*>Dest Store No\. <i class="fa-solid fa-sort-up sort-icon"><\/i><\/th>/', $cnPage) === 1
);
$toolbar = [
    'credit-note-start' => 'ดึงข้อมูลจาก upload here',
    'credit-note-open-upload' => 'upload here',
    'credit-note-open-finished' => 'finished',
    'credit-note-export' => 'Excel (.xlsx)',
    'credit-note-clear' => 'ล้างข้อมูลใบลดหนี้',
];
$missingButtons = [];
foreach ($toolbar as $id => $label) {
    if (!preg_match('/<button\b[^>]*id="' . $id . '"[^>]*>(.*?)<\/button>/s', $cnPage, $b) || plainText($b[1]) !== $label) {
        $missingButtons[] = $id;
    }
}
check(
    'the credit note toolbar has the extract button with its stop button, the two folder buttons, the search box, Excel and clear',
    $missingButtons === [] && strpos($cnPage, 'id="credit-note-stop"') !== false && strpos($cnPage, 'id="credit-note-search"') !== false
        && strpos($cnPage, "onclick=\"openJobFolder('cn_upload')\"") !== false && strpos($cnPage, "onclick=\"openJobFolder('cn_finished')\"") !== false,
    json_encode($missingButtons)
);
$tabs = function (string $html): array {
    preg_match_all('/<button class="header-tab-btn( active)?" id="([^"]+)" type="button" data-tab="([^"]+)">(.*?)<\/button>/s', $html, $m, PREG_SET_ORDER);
    return array_map(function ($tab) {
        return ['id' => $tab[2], 'tab' => $tab[3], 'label' => plainText($tab[4]), 'active' => $tab[1] !== ''];
    }, $m);
};
$sectionShown = function (string $html, string $id): ?string {
    return preg_match('/<div id="' . $id . '"[^>]*style="display: (flex|none);"/', $html, $m) ? $m[1] : null;
};
$tabsOf = $tabs($cnPage);
$asnTabPage = page('credit_note.php', ['job_id' => $jobId, 'tab' => 'asn']);
$dashTabPage = page('credit_note.php', ['job_id' => $jobId, 'tab' => 'dashboard']);
check(
    'the credit note page leads back to the portal of its Job and has three tabs in order, the first open, and ?tab=asn or ?tab=dashboard opens that tab',
    preg_match('/<div class="app-brand">\s*<a href="job.php\?job_id=' . $jobId . '"/', $cnPage) === 1
        && substr_count($cnPage, 'class="header-tab-btn') === 3
        && array_column($tabsOf, 'label') === ['1. ดึงข้อมูลจากใบลดหนี้ (PDF)', '2. เปรียบเทียบกับไฟล์ ASN', '3. ภาพรวม']
        && array_column($tabsOf, 'tab') === ['pdf', 'asn', 'dashboard'] && array_column($tabsOf, 'active') === [true, false, false]
        && [$sectionShown($cnPage, 'credit-note-section'), $sectionShown($cnPage, 'credit-note-asn-section'), $sectionShown($cnPage, 'credit-note-dash-section')] === ['flex', 'none', 'none']
        && array_column($tabs($asnTabPage), 'active') === [false, true, false] && $sectionShown($asnTabPage, 'credit-note-asn-section') === 'flex'
        && array_column($tabs($dashTabPage), 'active') === [false, false, true] && $sectionShown($dashTabPage, 'credit-note-dash-section') === 'flex'
        && $sectionShown($dashTabPage, 'credit-note-section') === 'none',
    json_encode($tabsOf, JSON_UNESCAPED_UNICODE)
);
preg_match('/id="credit-note-status-counts">(.*?)<\/div>\s*<a /s', $cnPage, $status);
check(
    'the status bar of the credit note page is rendered with the files and documents of the Job and its ASN sheets and rows',
    plainText($status[1] ?? '') === 'ไฟล์: 2 | ใบลดหนี้: 2 | ASN: 1 ชีต | รายการ: 2',
    plainText($status[1] ?? '')
);
$freshCn = page('credit_note.php', ['job_id' => $freshJob['id']]);
$placeholders = function (string $html, string $tbodyId): int {
    preg_match('/<tbody id="' . $tbodyId . '">(.*?)<\/tbody>/s', $html, $m);
    return substr_count($m[1] ?? '', 'class="placeholder-grid-row"');
};
check(
    'an empty documents table and an empty compare grid show the blank grid rows of the other pages; a Job with documents and ASN rows shows none and opens tab 2 on the compare step',
    $placeholders($freshCn, 'credit-note-tbody') === 18 && $placeholders($freshCn, 'credit-note-asn-tbody') === 18
        && $placeholders($cnPage, 'credit-note-tbody') === 0 && $placeholders($cnPage, 'credit-note-asn-tbody') === 0
        && strpos($cnPage, 'id="credit-note-asn-section" data-step="compare"') !== false
        && strpos($freshCn, 'id="credit-note-asn-section" data-step="select"') !== false
        && preg_match('/<button[^>]*id="credit-note-compare" type="button" disabled>/', $freshCn) === 1
);
$finishedText = 'myfile/Portal _A&amp;B/CREDIT NOTE/finished/';
check(
    'the notice for credit notes stored before the reader names the real finished folder of the Job, written by the server, and starts hidden',
    preg_match('/<div class="dashboard-notice cn-reread-notice d-none" id="credit-note-reread-notice">.*?<span class="tabular-num" id="credit-note-reread-folder">' . preg_quote($finishedText, '/') . '<\/span>.*?id="credit-note-reread">\s*<i[^>]*><\/i> ดึงข้อมูลใหม่จาก finished/s', $cnPage) === 1
);
check(
    'the clear dialog says the PDFs stay in finished and how to read them again',
    strpos($cnPage, 'ไฟล์ PDF ไม่ถูกลบ ยังอยู่ในโฟลเดอร์ CREDIT NOTE/finished') !== false
        && strpos($cnPage, 'ให้ย้ายไฟล์กลับไปที่ CREDIT NOTE/upload here') !== false
);
check(
    'the status bar of each system names its own folder, myfile/<Job>/TAX INVOICE/ on workspace.php and myfile/<Job>/CREDIT NOTE/ on credit_note.php, and opens it',
    preg_match('/onclick="openJobFolder\(\'ti\'\); return false;">\s*<i[^>]*><\/i>\s*โฟลเดอร์: <span class="tabular-num">myfile\/Portal _A&amp;B\/TAX INVOICE\/<\/span>/u', $workspace) === 1
        && preg_match('/onclick="openJobFolder\(\'cn\'\); return false;">\s*<i[^>]*><\/i>\s*โฟลเดอร์: <span class="tabular-num">myfile\/Portal _A&amp;B\/CREDIT NOTE\/<\/span>/u', $cnPage) === 1
);
$cnScripts = localScripts($cnPage);
check(
    'the credit note page loads the column colour script last, after its own scripts',
    end($cnScripts) === 'assets/js/workspace/column-highlight.js' && in_array('assets/js/credit-note/page.js', $cnScripts, true)
        && preg_match_all('/<script src="assets\/[^"]+\?v=\d+"/', $cnPage) === count($cnScripts),
    json_encode($cnScripts)
);

$legacy = (string)file_get_contents(__DIR__ . '/../read_cn_error01.php');
$sharedIds = array_values(array_intersect(elementIds($cnPage), elementIds($legacy)));
check(
    'the credit note page and the legacy page read_cn_error01.php share no element id',
    $sharedIds === [] && count(elementIds($legacy)) > 10,
    json_encode($sharedIds)
);
$cnNames = pageScriptNames($cnPage);
$legacyNames = pageScriptNames($legacy);
$sharedScripts = array_intersect(localScripts($cnPage), localScripts($legacy));
$ownNames = function (array $names) use ($sharedScripts): array {
    return array_keys(array_filter($names, function ($sources) use ($sharedScripts) {
        return array_diff($sources, $sharedScripts) !== [];
    }));
};
$clashes = array_values(array_intersect($ownNames($cnNames), $ownNames($legacyNames)));
$twice = array_keys(array_filter($cnNames, function ($sources) {
    return count(array_unique($sources)) > 1;
}));
check(
    'no script of the credit note page declares a global name a legacy page script declares, and no name is declared twice on the credit note page',
    $clashes === [] && $twice === [] && in_array('cnData', $ownNames($legacyNames), true) && in_array('creditNoteState', $ownNames($cnNames), true),
    json_encode([$clashes, $twice])
);

// The two pages share ui-utils.js and column-highlight.js; the ids those scripts look up are the only ids both may have
$bothScripts = array_values(array_intersect(localScripts($cnPage), localScripts($workspace)));
$idsOfSharedScripts = [];
foreach ($bothScripts as $src) {
    preg_match_all("/getElementById\('([^']+)'\)/", (string)file_get_contents(__DIR__ . '/../' . $src), $m);
    $idsOfSharedScripts = array_merge($idsOfSharedScripts, $m[1]);
}
$sharedWithWorkspace = array_values(array_intersect(elementIds($cnPage), elementIds($workspace)));
$workspaceNames = pageScriptNames($workspace);
$ownOf = function (array $names) use ($bothScripts): array {
    return array_keys(array_filter($names, function ($sources) use ($bothScripts) {
        return array_diff($sources, $bothScripts) !== [];
    }));
};
$nameClashes = array_values(array_intersect($ownOf($cnNames), $ownOf($workspaceNames)));
check(
    'the credit note page and workspace.php share only the ids the scripts both load look up (the loading overlay), and their own scripts declare no global name in common but CURRENT_JOB_ID, which those scripts read',
    $bothScripts === ['assets/vendor/xlsx.full.min.js', 'assets/js/ui-utils.js', 'assets/js/workspace/column-highlight.js']
        && array_diff($sharedWithWorkspace, $idsOfSharedScripts) === [] && in_array('app-loading-overlay', $sharedWithWorkspace, true)
        && $nameClashes === ['CURRENT_JOB_ID'] && in_array('detectSheetColumns', $ownOf($workspaceNames), true) && in_array('creditNoteDetectSheetColumns', $ownOf($cnNames), true),
    json_encode([array_values(array_diff($sharedWithWorkspace, $idsOfSharedScripts)), $nameClashes])
);

$summary = api('job_delete_summary', [], ['job_id' => $jobId]);
$index = node('index_navigation.js', [json_encode($summary['data'] ?? [])]);
if ($index === null) {
    echo "SKIP  the Job list script (node is not on PATH)\n";
} else {
    check(
        'opening a Job from the list, and creating one, goes to its portal job.php',
        ($index['openJob'] ?? '') === 'job.php?job_id=7' && ($index['afterCreate'] ?? '') === 'job.php?job_id=42',
        json_encode([$index['openJob'] ?? null, $index['afterCreate'] ?? null])
    );
    check(
        'the delete dialog lists the credit notes of the Job when it has any, in the wording of the other lines',
        in_array('ใบลดหนี้ 2 ไฟล์ (2 ใบ)', $index['serverLines'] ?? [], true)
            && in_array('ใบลดหนี้ 1,234 ไฟล์ (5 ใบ)', $index['withCreditNotes'] ?? [], true)
            && preg_grep('/ใบลดหนี้/u', $index['withoutCreditNotes'] ?? ['ใบลดหนี้']) === [],
        json_encode([$index['serverLines'] ?? null, $index['withCreditNotes'] ?? null], JSON_UNESCAPED_UNICODE)
    );
    check(
        'the delete dialog names TAX INVOICE/upload here for the PDFs still waiting there',
        in_array('ไฟล์ PDF ที่ยังรอดึงข้อมูลในโฟลเดอร์ TAX INVOICE/upload here 4 ไฟล์', $index['withCreditNotes'] ?? [], true),
        json_encode($index['withCreditNotes'] ?? null, JSON_UNESCAPED_UNICODE)
    );
}

$hl = node('column_highlight.js', [$workspace, $cnPage]);
if ($hl === null) {
    echo "SKIP  the column colours of the credit note table (node is not on PATH)\n";
} else {
    $at = function (string $tableId, string $key) use ($hl): int {
        return array_search($key, $hl['headers'][$tableId] ?? [], true) + 1;
    };
    $css = $hl['creditNote']['css'] ?? '';
    check(
        'a colour chosen for a column of any of the three credit note tables paints that column by its position, and the colours of the Tax Invoice tables of the same Job are kept',
        $at('credit-note-table', 'doc_no') === 4 && $at('credit-note-asn-table', 'remark') === 14 && $at('credit-note-dash-table', 'status') === 8
            && strpos($css, "#credit-note-table tbody td:nth-child(4):not([colspan]) { background-color: #d0bfff !important; }") !== false
            && strpos($css, "#credit-note-asn-table tbody td:nth-child(14):not([colspan]) { background-color: #ffd8a8 !important; }") !== false
            && strpos($css, "#credit-note-dash-table thead th:nth-child(8) { background-color: #a5d8ff !important;") !== false
            && ($hl['creditNote']['stored'] ?? null) === ['detailed-table' => [], 'asn-table' => ['barcode' => '#fff59d'], 'dash-table' => [], 'credit-note-table' => ['doc_no' => '#d0bfff'], 'credit-note-asn-table' => ['remark' => '#ffd8a8'], 'credit-note-dash-table' => ['status' => '#a5d8ff'], 'vbrp-table' => []],
        json_encode($hl['creditNote']['stored'] ?? null)
    );
}

$table = node('credit_note_table.js', [$cnPage]);
if ($table === null) {
    echo "SKIP  the credit note table script (node is not on PATH)\n";
} else {
    $texts = function (array $cells): array {
        return array_column($cells, 'text');
    };
    $row2 = $table['row2'] ?? [];
    $row3 = $table['row3'] ?? [];
    check(
        'a credit note row shows its number, file, pages, number, date as dd/mm/yyyy, customer, type and the amounts with two decimals (the right-hand totals last), right aligned',
        $texts($row2) === ['2', 'a.pdf', '1-3', '93250520-2', '02/11/2026', '5024-CENTRAL MEGA BANGNA 10158', 'Attachment Sheet', '5,000.00', '4,000.00', '1,000.00', '70.00', '1,234.50', '1,153.74', '80.76', '1,234.50']
            && array_reduce(array_slice($row2, 7), function ($ok, $cell) {
                return $ok && in_array('text-end', $cell['classes'], true);
            }, true),
        json_encode($texts($row2))
    );
    check(
        'a credit note stored before the reader shows its printed date as dd/mm/yyyy and dashes for the totals it does not have, and only such a document asks to be read again',
        array_slice($texts($table['row1'] ?? []), 4, 1) === ['11/10/2026'] && array_slice($texts($table['row1'] ?? []), 12) === ['–', '–', '–']
            && array_slice($texts($table['row4'] ?? []), 4, 1) === ['30/09/2026']
            && ($table['reread'] ?? null) === [true, false, false, false],
        json_encode([$texts($table['row1'] ?? []), $table['reread'] ?? null])
    );
    $dashes = array_values(array_filter($row3, function ($cell) {
        return $cell['text'] === '–';
    }));
    check(
        'a missing amount, number, date or customer shows a quiet dash, not 0.00',
        $texts($row3) === ['3', 'c.pdf', '2', '–', '–', '–', 'Variance / Refund', '10.00', '8.00', '2.00', '–', '–', '–', '–', '–']
            && count($dashes) === 8 && array_reduce($dashes, function ($ok, $cell) {
                return $ok && in_array('text-null-subtle', $cell['classes'], true);
            }, true),
        json_encode($texts($row3), JSON_UNESCAPED_UNICODE)
    );
    $typeClass = function (array $row) {
        preg_match('/class="pill-badge ([\w-]+)"/', $row[6]['html'] ?? '', $m);
        return $m[1] ?? null;
    };
    check(
        'the Type is a small pill and each of the three types has its own colour; a file name is escaped',
        count(array_unique([$typeClass($row2), $typeClass($row3), $typeClass($table['row4'] ?? [])])) === 3
            && !in_array(null, [$typeClass($row2), $typeClass($row3), $typeClass($table['row4'] ?? [])], true)
            && ($table['row4'][1]['text'] ?? '') === 'd <x>.pdf' && strpos($table['row4'][1]['html'] ?? '', '<x>') === false,
        json_encode([$typeClass($row2), $typeClass($row3), $typeClass($table['row4'] ?? [])])
    );
    check(
        'sorting orders amounts as numbers with missing ones last both ways, dates by day across months, file names, and # back to front',
        ($table['sorted'] ?? null) === [
            'amountAsc' => [4, 1, 2, 3], 'amountDesc' => [2, 1, 4, 3], 'dateAsc' => [4, 1, 2, 3],
            'fileAsc' => [2, 1, 3, 4], 'numberDesc' => [4, 3, 2, 1],
        ],
        json_encode($table['sorted'] ?? null)
    );
    check(
        'the search box keeps rows that hold every word typed, in any column, any case, amounts with or without the comma, and dates as shown or as printed',
        ($table['searched'] ?? null) === ['docNo' => [2], 'twoWords' => [1], 'typeAnyCase' => [2], 'amountWithComma' => [2], 'amountPlain' => [1], 'dateAsShown' => [4], 'dateAsPrinted' => [1], 'nothing' => []],
        json_encode($table['searched'] ?? null)
    );
    $grid = $table['excel']['grid'] ?? [];
    $column = function (int $c) use ($grid): array {
        return array_map(function ($row) use ($c) {
            return $row[$c]['v'] ?? null;
        }, array_slice($grid, 1));
    };
    $amountFormats = [];
    foreach (array_slice($grid, 1) as $row) {
        foreach (array_slice($row, 7) as $cell) {
            if ($cell !== null) {
                $amountFormats[] = $cell['t'] . ' ' . $cell['z'];
            }
        }
    }
    check(
        'Excel exports the rows as listed to sheet Credit_Notes of Job_{id}_{name}_Credit_Notes.xlsx: the headers of the table, amounts as numbers shown #,##0.00, missing values as empty cells',
        ($table['excel']['file'] ?? '') === "Job_{$jobId}_Portal__A_B__Credit_Notes.xlsx" && ($table['excel']['sheets'] ?? null) === ['Credit_Notes']
            && array_map(function ($cell) {
                return $cell['v'] ?? null;
            }, $grid[0] ?? []) === $headers
            && $column(0) === [1, 2, 3, 4] && $column(3) === ['93250520-2', '93250403-1', '93249881-1', null]
            && $column(4) === ['02/11/2026', '11/10/2026', '30/09/2026', null]
            && $column(11) === [1234.5, 1070, 107, null] && $column(7) === [5000, null, null, 10] && $column(13) === [80.76, null, 7, null]
            && array_values(array_unique($amountFormats)) === ['n #,##0.00'] && count($amountFormats) === 18,
        json_encode([$table['excel']['file'] ?? null, $grid[0] ?? null, $grid[4] ?? null], JSON_UNESCAPED_UNICODE)
    );
}

$cmp = node('credit_note_compare.js', [$cnPage]);
if ($cmp === null) {
    echo "SKIP  the compare and overview scripts of the credit note page (node is not on PATH)\n";
} else {
    $workbook = basename(glob(__DIR__ . '/fixtures/ASN S4 *.xlsx')[0]);
    $sheets = $cmp['sheets'] ?? [];
    $hintOk = array_reduce($sheets, function ($ok, $sheet) {
        $marked = substr($sheet['option'], -strlen(' น่าจะเป็นใบลดหนี้')) === ' น่าจะเป็นใบลดหนี้';
        return $ok && $marked === $sheet['hint'];
    }, $sheets !== []);
    check(
        'the sheet picker offers the visible sheets of the ASN workbook that have a document number and a barcode, in workbook order, defaults to the first (not a credit note sheet: it never picks for the user) and marks only the ZCR and ZKR sheets "น่าจะเป็นใบลดหนี้"',
        array_column($sheets, 'name') === ['CDS ZFP (ZKE) done 13.10.2026', 'CDS ZG2T (ZCR) done', 'CDS ZFP3 (ZKR) done 11.10.2026', 'CDS ZL2T (ZDR) done', 'RBS ZFP (ZKE) done 13.10.2026', 'RBS ZG2T (ZCR) done', 'RBS ZFP3 (ZKR) done 13.10.2026', 'RBS ZL2T (ZDR) done']
            && array_column($sheets, 'hint') === [false, true, true, false, false, true, true, false] && $hintOk,
        json_encode(array_column($sheets, 'option'), JSON_UNESCAPED_UNICODE)
    );
    check(
        'on all 19 sheets of the workbook the credit note importer finds the header row and the columns the Tax Invoice importer finds, plus the date column',
        ($cmp['detectionParity'] ?? null) === [] && ($cmp['detectedSheets'] ?? 0) === 19,
        json_encode($cmp['detectionParity'] ?? null, JSON_UNESCAPED_UNICODE)
    );
    // Rows, credit notes and first lines counted with openpyxl from the workbook, independently of the page code
    $expectedSheets = [
        'CDS ZG2T (ZCR) done' => [1890, 197, 'retail'],
        'RBS ZG2T (ZCR) done' => [2067, 254, 'retail'],
        'CDS ZFP3 (ZKR) done 11.10.2026' => [148, 69, 'cost'],
        'RBS ZFP3 (ZKR) done 13.10.2026' => [99, 37, 'cost'],
    ];
    $sheetFaults = [];
    foreach ($cmp['creditNoteSheets'] ?? [] as $sheet) {
        $want = $expectedSheets[$sheet['name']] ?? null;
        if ($want === null || [$sheet['rows'], $sheet['documents'], $sheet['kinds']] !== [$want[0], $want[1], [$want[2]]]
            || $sheet['keys'] !== ['invoice_no,invoice_date,dest_store_no,ean_upc,quantity,selling_price,total_cost_ex_vat,total_vat,delivery_no,amount_kind']
            || $sheet['source'] !== "$workbook / {$sheet['name']}") {
            $sheetFaults[] = $sheet['name'];
        }
    }
    $firsts = array_column($cmp['creditNoteSheets'] ?? [], 'first', 'name');
    check(
        'each of the four credit note sheets gives one record per barcode line with the same keys, cost for the ZFP3 sheets (Total Cost Ex VAT) and retail for the ZG2T sheets (Unit Ret), 557 credit notes together, stored as "<workbook> / <sheet>"',
        $sheetFaults === [] && count($cmp['creditNoteSheets'] ?? []) === 4
            && ($firsts['CDS ZG2T (ZCR) done'] ?? null) === ['invoice_no' => '93240270', 'invoice_date' => '2026-04-27', 'dest_store_no' => '10103', 'ean_upc' => '5401180818211', 'quantity' => 1, 'selling_price' => 2390, 'total_cost_ex_vat' => 0, 'total_vat' => 0, 'delivery_no' => '', 'amount_kind' => 'retail']
            && ($firsts['CDS ZFP3 (ZKR) done 11.10.2026'] ?? null) === ['invoice_no' => '93248267-1', 'invoice_date' => '2026-04-11', 'dest_store_no' => '10116', 'ean_upc' => '5401221356665', 'quantity' => 1, 'selling_price' => 951.39, 'total_cost_ex_vat' => 6271.4, 'total_vat' => 438.99, 'delivery_no' => '5101100656', 'amount_kind' => 'cost'],
        json_encode([$sheetFaults, $firsts], JSON_UNESCAPED_UNICODE)
    );

    $compare = $cmp['compare'] ?? [];
    $rowTexts = array_map($texts, $compare['rows'] ?? []);
    $cellHtml = function (int $row, int $col) use ($compare): string {
        return $compare['rows'][$row][$col]['html'] ?? '';
    };
    check(
        'a compared line of a cost sheet sets the PDF Total Amt Excluding VAT against the ASN Total Cost Ex VAT and shows both VATs; a complete line says ข้อมูลถูกต้อง in green',
        ($rowTexts[0] ?? null) === ['1', 'สมบูรณ์', '93248267-1', '5013_93248267-1_DN.issue.at_20261011_5013-CENTRAL BANGNA 10116.pdf', '5401221356665', '501 ORIGINAL <BLUE>', '10116', '10116', '1', '1', '6,271.40', '6,271.40', '438.99 / 438.99', 'ข้อมูลถูกต้อง']
            && strpos($cellHtml(0, 11), 'cell-match') !== false && strpos($cellHtml(0, 13), 'class="remark-match"') !== false
            && strpos($cellHtml(0, 5), '<BLUE>') === false,
        json_encode($rowTexts[0] ?? null, JSON_UNESCAPED_UNICODE)
    );
    check(
        'a compared line of a retail sheet sets the PDF Total Amount (not Excluding VAT) against quantity x Unit Ret, marks a gap red, and shows the PDF VAT with an empty ASN VAT that says why',
        ($rowTexts[2] ?? null) === ['3', 'ผิดพลาด', '93240270', '5002_93240270_DN.pdf', '5401180818211', 'TRUCKER JACKET', '10103', '10103', '1', '1', '2,380.00', '2,390.00', '155.70 / –', 'ปัญหา : ยอดรวมไม่ตรง (PDF: 2,380.00 / ASN: 2,390.00)']
            && strpos($cellHtml(2, 11), 'cell-mismatch') !== false && strpos($cellHtml(2, 13), 'class="remark-mismatch"') !== false
            && strpos((string)($compare['rows'][2][12]['title'] ?? ''), 'ไม่มี VAT ใน ASN') !== false,
        json_encode($rowTexts[2] ?? null, JSON_UNESCAPED_UNICODE)
    );
    check(
        'merged ASN rows show their count, a quantity gap is red, a credit note with no PDF shows dashes on the PDF side, and a barcode only in the PDF keeps the document figures of the ASN with a dash for its ASN quantity',
        ($rowTexts[1][9] ?? '') === '3 (2 แถว)' && strpos($cellHtml(1, 9), 'cell-mismatch') !== false
            && ($rowTexts[1][3] ?? '') === '5013_93248267-1_DN.issue.at_20261011_5013-CENTRAL BANGNA 10116.pdf+1 ไฟล์'
            && ($rowTexts[3] ?? null) === ['4', 'ผิดพลาด', '93240300', '–', '6901462126830', '–', '–', '20186', '–', '2', '–', '1,500.00', '–', 'ปัญหา : ไม่พบใบลดหนี้นี้ใน PDF']
            && array_slice($rowTexts[4] ?? [], 6) === ['–', '10150', '4', '–', '467.29', '467.29', '32.71 / 32.71', 'ปัญหา : บาร์โค้ด 8850000000001 มีใน PDF แต่ไม่มีใน ASN (4 ชิ้น)'],
        json_encode([$rowTexts[1] ?? null, $rowTexts[3] ?? null, $rowTexts[4] ?? null], JSON_UNESCAPED_UNICODE)
    );
    check(
        'the status chips count every line, a chip keeps only its status, and the toolbar step goes select, compare, done',
        ($compare['counts'] ?? null) === ['ALL' => 5, 'MATCH' => 1, 'MISMATCH' => 4, 'PENDING' => 0]
            && ($compare['filtered'] ?? null) === ['mismatch' => [1, 2, 3, 4], 'match' => [0], 'pendingNone' => [], 'mismatchSearched' => [1]]
            && ($compare['steps'] ?? null) === ['select', 'compare', 'done', 'select'],
        json_encode([$compare['counts'] ?? null, $compare['filtered'] ?? null, $compare['steps'] ?? null])
    );
    check(
        'the compare grid sorts the wrong lines first by Status, numbers as numbers with empty cells last both ways, and # back to front',
        ($compare['sorted'] ?? null) === [
            'statusAsc' => [1, 2, 3, 4, 0], 'pdfQtyAsc' => [0, 2, 1, 4, 3], 'pdfQtyDesc' => [4, 1, 0, 2, 3],
            'asnAmountDesc' => [0, 1, 2, 3, 4], 'docAsc' => [2, 3, 0, 1, 4], 'numberDesc' => [4, 3, 2, 1, 0],
        ],
        json_encode($compare['sorted'] ?? null)
    );
    check(
        'the compare search keeps lines that hold every word, in the number, the remark, the description in any case, and amounts with or without the comma',
        ($compare['searched'] ?? null) === ['docNo' => [2], 'remark' => [3], 'amountWithComma' => [0, 1], 'amountPlain' => [0, 1], 'twoWords' => [2], 'anyCase' => [0], 'nothing' => []],
        json_encode($compare['searched'] ?? null)
    );
    $pending = $cmp['pending'] ?? [];
    $pendingTexts = array_map($texts, $pending['rows'] ?? []);
    check(
        'imported ASN lines wait as รอเทียบกับ PDF with what to do, and show the credit note amount on its basis: the first positive Total Cost Ex VAT of a cost sheet, the sum of quantity x Unit Ret of a retail sheet',
        ($pending['statuses'] ?? null) === array_fill(0, 7, 'PENDING')
            && ($pending['docAmounts'] ?? null) === [4390.01, 4390.01, 6271.4, 6271.4, 99.5, 250, 250]
            && array_slice($pendingTexts[0] ?? [], 11) === ['4,390.01', '–', 'ปัญหา : รอการเปรียบเทียบกับ PDFวิธีแก้ไข : กดปุ่ม "เทียบกับ PDF" ด้านบนเพื่อเริ่มเปรียบเทียบ']
            && array_slice($pendingTexts[1] ?? [], 11, 2) === ['6,271.40', '– / 438.99'],
        json_encode([$pending['docAmounts'] ?? null, $pendingTexts], JSON_UNESCAPED_UNICODE)
    );
    check(
        'the clear dialog lists each imported sheet once with the ids of its rows, its row count and its credit notes',
        ($pending['sources'] ?? null) === [
            ['source' => 'wb.xlsx / CDS ZG2T (ZCR) done', 'ids' => [11, 12], 'rows' => 2, 'documents' => 1],
            ['source' => 'wb.xlsx / CDS ZFP3 (ZKR) done 11.10.2026', 'ids' => [13, 14, 15, 16, 17], 'rows' => 5, 'documents' => 3],
        ],
        json_encode($pending['sources'] ?? null, JSON_UNESCAPED_UNICODE)
    );
    $remarks = $cmp['remarks'] ?? [];
    $remarkView = function (array $lines): array {
        return array_map(function ($line) {
            return $line['label'] . $line['text'] . ' [' . $line['className'] . ']';
        }, $lines);
    };
    check(
        'remarks follow the Tax Invoice rules: a wrong line is one red ปัญหา line with its problems joined by commas, a complete line says ข้อมูลถูกต้อง, a waiting line names what to do except in the overview',
        $remarkView($remarks['mismatch'] ?? []) === ['ปัญหา : จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 3 ชิ้น), ยอดรวมไม่ตรง (PDF: 11.00 / ASN: 10.00) [remark-mismatch]']
            && $remarkView($remarks['match'] ?? []) === ['ข้อมูลถูกต้อง [remark-match]']
            && $remarkView($remarks['pendingCompare'] ?? []) === ['ปัญหา : รอการเปรียบเทียบกับ PDF [remark-waiting]', 'วิธีแก้ไข : กดปุ่ม "เทียบกับ PDF" ด้านบนเพื่อเริ่มเปรียบเทียบ [remark-waiting]']
            && $remarkView($remarks['pendingOverview'] ?? []) === ['ปัญหา : รอการเปรียบเทียบกับ PDF [remark-waiting]'],
        json_encode($remarks, JSON_UNESCAPED_UNICODE)
    );
    check(
        'the ASN label shows the one sheet in full, or "4 ชีต" with the full list on hover, so four sheets keep the toolbar on one line',
        ($cmp['labels'] ?? null) === [
            ['text' => 'ยังไม่ได้เลือกไฟล์ ASN', 'title' => 'ยังไม่ได้เลือกไฟล์ ASN'],
            ['text' => 'wb.xlsx / CDS ZG2T (ZCR) done', 'title' => 'wb.xlsx / CDS ZG2T (ZCR) done'],
            ['text' => '4 ชีต', 'title' => '4 ชีต: wb.xlsx / a, wb.xlsx / b, wb.xlsx / c, wb.xlsx / d'],
        ],
        json_encode($cmp['labels'] ?? null, JSON_UNESCAPED_UNICODE)
    );
    preg_match('/const CN_TOLERANCES = (.*?);\n/', $cnPage, $tolerances);
    preg_match_all('/data-cn-rule="(amount|vat)"/', $cnPage, $ruleMarks);
    check(
        'the money rule is worded from the tolerances CreditNoteReconciler applies (exact to the satang today, any other value names the baht): the page passes them and marks both amount headers, the VAT header and the compare dialog',
        json_decode($tolerances[1] ?? 'null', true) == ['amount' => App\CreditNoteReconciler::AMOUNT_TOLERANCE, 'vat' => App\CreditNoteReconciler::VAT_TOLERANCE]
            && ($cmp['rules'] ?? null) === ['ต้องตรงกันทุกสตางค์', 'ต้องตรงกันทุกสตางค์', 'ต่างกันได้ไม่เกิน 1.00 บาท', 'ต่างกันได้ไม่เกิน 0.15 บาท']
            && $ruleMarks[1] === ['amount', 'amount', 'vat', 'amount', 'vat']
            && strpos($cnPage, 'ทุกสตางค์') === false,
        json_encode([$tolerances[1] ?? null, $cmp['rules'] ?? null, $ruleMarks[1]], JSON_UNESCAPED_UNICODE)
    );
    check(
        'dates read dd/mm/yyyy from the reader date or the printed one (dots or dashes), and an Excel date of the ASN becomes yyyy-mm-dd',
        ($cmp['dates'] ?? null) === ['06/04/2026', '11/04/2026', null, '2026-04-27', '27/04/2026'],
        json_encode($cmp['dates'] ?? null)
    );

    $dash = $cmp['dashboard'] ?? [];
    $dashTexts = array_map($texts, $dash['rows'] ?? []);
    check(
        'an overview row shows the store, the credit note, its PDF files, units, the ASN amount, pages, status and remark, with dashes where the ASN credit note has no store or no PDF',
        ($dashTexts[0] ?? null) === ['1', '10103', '93240270', '5002_93240270_DN.pdf', '1', '2,390.00', '2', 'ผิดพลาด', 'ปัญหา : ยอดรวมไม่ตรง (PDF: 2,380.00 / ASN: 2,390.00)']
            && ($dashTexts[1] ?? null) === ['2', '10103', '93240271', 'a.pdf+2 ไฟล์', '1', '990.00', '1', 'สมบูรณ์', 'ข้อมูลถูกต้อง']
            && ($dashTexts[2] ?? null) === ['4', '–', '93240300', '–', '2', '1,500.00', '–', 'ผิดพลาด', 'ปัญหา : ไม่พบใบลดหนี้นี้ใน PDF'],
        json_encode($dashTexts, JSON_UNESCAPED_UNICODE)
    );
    check(
        'the overview sorts by store then credit note number (a credit note without a store last, both ways), by amount and status; search and the status chips narrow it',
        ($dash['sorted'] ?? null) === [
            'storeAsc' => ['93240270', '93240271', '93248267-1', '93240300'], 'storeDesc' => ['93248267-1', '93240270', '93240271', '93240300'],
            'amountDesc' => ['93248267-1', '93240270', '93240300', '93240271'], 'statusAsc' => ['93240270', '93248267-1', '93240300', '93240271'],
        ]
            && ($dash['searched'] ?? null) === ['missing' => ['93240300'], 'status' => ['93240271'], 'amount' => ['93248267-1'], 'file' => ['93240271']]
            && ($dash['filtered'] ?? null) === ['match' => ['93240271'], 'mismatchWithWord' => ['93240270']],
        json_encode([$dash['sorted'] ?? null, $dash['searched'] ?? null, $dash['filtered'] ?? null], JSON_UNESCAPED_UNICODE)
    );
    $sheetCells = function (array $excel, int $row): array {
        return array_map(function ($cell) {
            return $cell === null ? null : ($cell['t'] === 'n' ? [$cell['v'], $cell['z']] : $cell['v']);
        }, $excel['grid'][$row] ?? []);
    };
    $compareExcel = $compare['excel'] ?? [];
    check(
        'Excel of the compare grid writes the lines as listed to sheet Credit_Note_Compare: the grid headers with VAT split in two and the basis of the ASN amount, money as numbers shown #,##0.00, empty cells where a side is missing',
        ($compareExcel['file'] ?? '') === "Job_{$jobId}_Portal__A_B__Credit_Note_Compare.xlsx" && ($compareExcel['sheets'] ?? null) === ['Credit_Note_Compare']
            && $sheetCells($compareExcel, 0) === ['#', 'Status', 'Credit Note No.', 'Source File', 'Barcode / EAN', 'Description', 'Store (PDF)', 'Store (ASN)', 'Qty (PDF)', 'Qty (ASN)', 'Amount (PDF)', 'Amount (ASN)', 'Amount Basis', 'VAT (PDF)', 'VAT (ASN)', 'Remark']
            && $sheetCells($compareExcel, 2) === [[2, 'General'], 'ผิดพลาด', '93240270', '5002_93240270_DN.pdf', '5401180818211', 'TRUCKER JACKET', '10103', '10103', [1, 'General'], [1, 'General'], [2380, '#,##0.00'], [2390, '#,##0.00'], 'Quantity x Unit Ret', [155.7, '#,##0.00'], null, 'ปัญหา : ยอดรวมไม่ตรง (PDF: 2,380.00 / ASN: 2,390.00)']
            && $sheetCells($compareExcel, 3) === [[3, 'General'], 'ผิดพลาด', '93240300', null, '6901462126830', null, null, '20186', null, [2, 'General'], null, [1500, '#,##0.00'], 'Quantity x Unit Ret', null, null, 'ปัญหา : ไม่พบใบลดหนี้นี้ใน PDF']
            && array_column(array_slice($compareExcel['grid'] ?? [], 1), 1) === array_map(function ($label) {
                return ['t' => 's', 'v' => $label, 'z' => 'General'];
            }, ['ผิดพลาด', 'ผิดพลาด', 'ผิดพลาด', 'ผิดพลาด', 'สมบูรณ์']),
        json_encode([$compareExcel['file'] ?? null, $sheetCells($compareExcel, 0), $sheetCells($compareExcel, 2)], JSON_UNESCAPED_UNICODE)
    );
    $dashExcel = $dash['excel'] ?? [];
    check(
        'Excel of the overview writes the rows as listed to sheet Credit_Note_Summary with the basis of each amount and the page count as a number',
        ($dashExcel['file'] ?? '') === "Job_{$jobId}_Portal__A_B__Credit_Note_Summary.xlsx" && ($dashExcel['sheets'] ?? null) === ['Credit_Note_Summary']
            && $sheetCells($dashExcel, 0) === ['#', 'Dest Store No.', 'Credit Note No.', 'Source File', 'Total Qty', 'Amount', 'Amount Basis', 'Pages', 'Status', 'Remark']
            && $sheetCells($dashExcel, 2) === [[2, 'General'], '10103', '93240271', 'a.pdf; b.pdf; c.pdf', [1, 'General'], [990, '#,##0.00'], 'Quantity x Unit Ret', [1, 'General'], 'สมบูรณ์', 'ข้อมูลถูกต้อง']
            && $sheetCells($dashExcel, 3) === [[3, 'General'], '10116', '93248267-1', '5013_93248267-1_DN.pdf', [4, 'General'], [6271.4, '#,##0.00'], 'Total Cost Ex VAT', [1, 'General'], 'ผิดพลาด', 'ปัญหา : จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 3 ชิ้น)']
            && $sheetCells($dashExcel, 4) === [[4, 'General'], null, '93240300', null, [2, 'General'], [1500, '#,##0.00'], 'Quantity x Unit Ret', null, 'ผิดพลาด', 'ปัญหา : ไม่พบใบลดหนี้นี้ใน PDF'],
        json_encode([$sheetCells($dashExcel, 0), $sheetCells($dashExcel, 4)], JSON_UNESCAPED_UNICODE)
    );
}

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
