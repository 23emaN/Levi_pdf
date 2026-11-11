<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\CreditNoteReconciler;
use App\FileManager;
use App\JobFolders;
use App\JobManager;

$jobId = intval($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    header('Location: index.php');
    exit;
}
// The compare with the ASN (the old tab 2) is closed (user 2026-11-11): its section stays in the page, hidden, so its
// code keeps working, but no button opens it and ?tab=asn opens tab 1
$initialTab = in_array($_GET['tab'] ?? '', ['dashboard', 'fill'], true) ? $_GET['tab'] : 'pdf';

if (!headers_sent() && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false && PHP_SAPI !== 'cli') {
    @ob_start('ob_gzhandler');
}

$jobManager = new JobManager();
$job = $jobManager->getJob($jobId);

if (!$job) {
    echo "<h1>Job #{$jobId} not found</h1><p><a href='index.php'>กลับหน้าแรก</a></p>";
    exit;
}

if (!$jobManager->jobFolderExists($job)) {
    header('Location: index.php');
    exit;
}

$cnCounts = $jobManager->creditNoteCounts($jobId);
$asnCounts = isset($cnCounts['asn']) ? $cnCounts['asn'] : ['files' => 0, 'rows' => 0];
$cnUploadCount = $jobManager->waitingCount($jobId, 'cn');
$cnUploadFolderText = JobFolders::displayPath($jobManager->getJobCnUploadDir($job)) . '/';
$cnAsnFolderText = JobFolders::displayPath($jobManager->getJobCnAsnDir($job)) . '/';
$cnFinishedText = JobFolders::displayPath($jobManager->getJobCnFinishedDir($job, false)) . '/';
$fillCounts = $jobManager->creditNoteFill()->counts($jobId);

// The rule sentence itself (exact to the satang today) is added in the browser from CN_TOLERANCES, which carries
// the tolerances CreditNoteReconciler applies (compare.js creditNoteApplyMoneyRules)
const CN_AMOUNT_RULE = 'ยอดเงินของทั้งใบลดหนี้ ชีตที่มีคอลัมน์ Total Cost Ex VAT ใช้ Total Cost Ex VAT ของ ASN เทียบกับ Total Amt Excluding VAT ของ PDF ชีตที่ไม่มีคอลัมน์นี้ (เช่น ZG2T) ใช้ผลรวม Quantity × Unit Ret ของ ASN เทียบกับ Total Amount ของ PDF';
const CN_VAT_RULE = 'ชีตที่ไม่มีคอลัมน์ Total Cost Ex VAT ไม่มี VAT ใน ASN จึงไม่เทียบ VAT ชีตที่มีใช้ VAT (7%) ของ PDF เทียบกับ Total VAT ของ ASN';

// One credit note document per row. The header, the cells, sorting, search and the Excel sheet all follow this list
// (assets/js/credit-note/table.js), and column-highlight.js paints a column by its position in it.
const CREDIT_NOTE_COLUMNS = [
    ['key' => '#', 'label' => '#', 'type' => 'number', 'class' => 'text-center', 'width' => 56],
    ['key' => 'filename', 'label' => 'File Name', 'type' => 'string', 'width' => 440],
    ['key' => 'pages', 'label' => 'Pages', 'type' => 'string', 'class' => 'text-center', 'width' => 80],
    ['key' => 'doc_no', 'label' => 'Credit Note No.', 'type' => 'string', 'width' => 140],
    ['key' => 'doc_date', 'label' => 'Date', 'type' => 'date', 'width' => 110],
    ['key' => 'customer', 'label' => 'Customer', 'type' => 'string', 'width' => 300],
    ['key' => 'format_type', 'label' => 'Type', 'type' => 'string', 'class' => 'text-center', 'width' => 150],
    ['key' => 'orig_amount', 'label' => 'Original Amt', 'type' => 'currency', 'class' => 'text-end', 'width' => 125],
    ['key' => 'corr_amount', 'label' => 'Corrected Amt', 'type' => 'currency', 'class' => 'text-end', 'width' => 130],
    ['key' => 'diff_amount', 'label' => 'Difference', 'type' => 'currency', 'class' => 'text-end', 'width' => 115],
    ['key' => 'vat_amount', 'label' => 'VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 110],
    ['key' => 'amount', 'label' => 'Amount', 'type' => 'currency', 'class' => 'text-end', 'width' => 125],
    ['key' => 'excl_vat', 'label' => 'Amt Excl VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 125, 'title' => 'Total Amt Excluding VAT ด้านขวาของใบลดหนี้'],
    ['key' => 'vat', 'label' => 'VAT (7%)', 'type' => 'currency', 'class' => 'text-end', 'width' => 110, 'title' => 'VAT (7%) ด้านขวาของใบลดหนี้'],
    ['key' => 'incl_vat', 'label' => 'Amt Incl VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 125, 'title' => 'Total Amt Include VAT ด้านขวาของใบลดหนี้'],
];

// One row per (credit note, barcode) after the compare, one per ASN line before it (assets/js/credit-note/compare-table.js
// holds the cells by key). The first three stay frozen at the left (app.css, #credit-note-asn-table).
const CN_COMPARE_COLUMNS = [
    ['key' => '#', 'label' => '#', 'type' => 'number', 'class' => 'text-center'],
    ['key' => 'status', 'label' => 'Status', 'type' => 'number', 'class' => 'text-center'],
    ['key' => 'doc_no', 'label' => 'Credit Note No.', 'type' => 'string'],
    ['key' => 'source_file', 'label' => 'Source File', 'type' => 'string', 'width' => 440, 'title' => 'ไฟล์ PDF ที่มีใบลดหนี้นี้'],
    ['key' => 'barcode', 'label' => 'Barcode / EAN', 'type' => 'string', 'width' => 140],
    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'width' => 230],
    ['key' => 'pdf_store', 'label' => 'Store (PDF)', 'type' => 'string', 'class' => 'text-center', 'width' => 110],
    ['key' => 'asn_store', 'label' => 'Store (ASN)', 'type' => 'string', 'class' => 'text-center', 'width' => 105],
    ['key' => 'pdf_qty', 'label' => 'Qty (PDF)', 'type' => 'number', 'class' => 'text-center', 'width' => 95],
    ['key' => 'asn_qty', 'label' => 'Qty (ASN)', 'type' => 'number', 'class' => 'text-center', 'width' => 110],
    ['key' => 'pdf_amount', 'label' => 'Amount (PDF)', 'type' => 'currency', 'class' => 'text-end', 'width' => 135, 'title' => CN_AMOUNT_RULE, 'rule' => 'amount'],
    ['key' => 'asn_amount', 'label' => 'Amount (ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 135, 'title' => CN_AMOUNT_RULE, 'rule' => 'amount'],
    ['key' => 'vat', 'label' => 'VAT (PDF / ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 150, 'title' => CN_VAT_RULE, 'rule' => 'vat'],
    ['key' => 'remark', 'label' => 'Remark', 'type' => 'string', 'width' => 520, 'title' => 'ปัญหาที่พบ'],
];

// Tab 4, one row per ASN line kept by "ใส่ยอด PDF ลง ASN" (assets/js/credit-note/asn-fill.js holds the cells by key).
// The ASN columns come from the line's own row when its sheet has them.
const CN_FILL_COLUMNS = [
    ['key' => '#', 'label' => '#', 'type' => 'number', 'class' => 'text-center', 'width' => 56],
    ['key' => 'status', 'label' => 'Status', 'type' => 'number', 'class' => 'text-center', 'width' => 130],
    ['key' => 'doc_no', 'label' => 'Credit Note No.', 'type' => 'string', 'width' => 140],
    ['key' => 'barcode', 'label' => 'Barcode / EAN', 'type' => 'string', 'width' => 140],
    ['key' => 'file', 'label' => 'ASN File', 'type' => 'string', 'width' => 260],
    ['key' => 'sheet', 'label' => 'Sheet', 'type' => 'string', 'width' => 200],
    ['key' => 'row_no', 'label' => 'Row', 'type' => 'number', 'class' => 'text-center', 'width' => 70, 'title' => 'แถวที่ในชีต Excel'],
    ['key' => 'quantity', 'label' => 'Qty (ASN)', 'type' => 'number', 'class' => 'text-center', 'width' => 95],
    ['key' => 'asn_cost', 'label' => 'Total Cost Ex VAT (ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 175],
    ['key' => 'asn_vat', 'label' => 'Total VAT (ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 130],
    ['key' => 'pdf_cost', 'label' => 'PDF_TOTAL_COST_EX_VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 190, 'title' => 'Total Amt Excluding VAT ของใบลดหนี้ใน PDF'],
    ['key' => 'pdf_vat', 'label' => 'PDF_TOTAL_VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 140, 'title' => 'VAT (7%) ของใบลดหนี้ใน PDF'],
    ['key' => 'pdf_file', 'label' => 'PDF File / Remark', 'type' => 'string', 'width' => 420, 'title' => 'ไฟล์ PDF ที่อ่านยอดมา หรือเหตุที่ไม่พบ'],
];

// One row per credit note number of the lines of tab 2 "ใส่ยอด PDF ลง ASN" (assets/js/credit-note/dashboard.js)
const CN_DASH_COLUMNS = [
    ['key' => '#', 'label' => '#', 'type' => 'number', 'class' => 'text-center', 'width' => 44],
    ['key' => 'store_no', 'label' => 'Dest Store No.', 'type' => 'string', 'width' => 96, 'title' => 'เลขที่สาขา: Store No / Dest Store No. ในไฟล์ ASN ตารางเรียงตามสาขา แล้วตามเลขใบลดหนี้'],
    ['key' => 'doc_no', 'label' => 'Credit Note No.', 'type' => 'string', 'width' => 110, 'title' => 'เลขใบลดหนี้'],
    ['key' => 'source_file', 'label' => 'PDF File', 'type' => 'string', 'width' => 212, 'title' => 'ไฟล์ PDF ที่อ่านยอดมา'],
    ['key' => 'units', 'label' => 'Qty (ASN)', 'type' => 'number', 'class' => 'text-center', 'width' => 72, 'title' => 'ผลรวม Quantity ของใบลดหนี้ในไฟล์ ASN'],
    ['key' => 'amount', 'label' => 'Cost Ex VAT (ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 124, 'title' => 'Total Cost Ex VAT ของใบลดหนี้ในไฟล์ ASN'],
    ['key' => 'pdf_cost', 'label' => 'PDF_TOTAL_COST_EX_VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 124, 'title' => 'Total Amt Excluding VAT ของใบลดหนี้ใน PDF'],
    ['key' => 'pdf_vat', 'label' => 'PDF_TOTAL_VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 100, 'title' => 'VAT (7%) ของใบลดหนี้ใน PDF'],
    ['key' => 'pages', 'label' => 'Pages', 'type' => 'string', 'class' => 'text-center', 'width' => 64, 'title' => 'จำนวนหน้าของใบลดหนี้ในไฟล์ PDF'],
    ['key' => 'status', 'label' => 'Status', 'type' => 'number', 'class' => 'text-center', 'width' => 112, 'title' => 'พบใบลดหนี้ใน PDF หรือไม่'],
    ['key' => 'remark', 'label' => 'Remark', 'type' => 'string', 'title' => 'หมายเหตุ'],
];

function renderCreditNoteHeader(array $columns, string $sortedKey = ''): string {
    $out = '';
    foreach ($columns as $col) {
        $sorted = $col['key'] === $sortedKey;
        $class = trim(($col['class'] ?? '') . ($sorted ? ' sorted' : ''));
        $attributes = ' data-col="' . htmlspecialchars($col['key']) . '" data-type="' . $col['type'] . '"'
            . ($class !== '' ? ' class="' . $class . '"' : '')
            . (isset($col['width']) ? ' style="width: ' . $col['width'] . 'px;"' : '')
            . (isset($col['title']) ? ' title="' . htmlspecialchars($col['title']) . '"' : '')
            . (isset($col['rule']) ? ' data-cn-rule="' . $col['rule'] . '"' : '');
        $icon = $sorted ? 'fa-sort-up' : 'fa-sort';
        $out .= "<th{$attributes}>" . htmlspecialchars($col['label']) . " <i class=\"fa-solid {$icon} sort-icon\"></i></th>\n";
    }
    return $out;
}

function renderCreditNotePlaceholderRows(int $columnCount, int $rowCount = 18): string {
    $out = '';
    $emptyCells = str_repeat('<td></td>', $columnCount - 1);
    for ($i = 1; $i <= $rowCount; $i++) {
        $out .= "<tr class=\"placeholder-grid-row\"><td class=\"text-center text-muted tabular-num opacity-40\">{$i}</td>{$emptyCells}</tr>";
    }
    return $out;
}

function creditNoteTabStyle(string $tab, string $initialTab): string {
    return 'display: ' . ($tab === $initialTab ? 'flex' : 'none') . ';';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($job['name']) ?> - CREDIT NOTE - Levi's PDF Data Extractor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>

<div id="app-loading-overlay" class="app-loading-overlay d-none">
    <div class="loading-card">
        <div class="loading-spinner-ring"></div>
        <div class="loading-title" id="loading-overlay-title">กำลังโหลดข้อมูลใบลดหนี้...</div>
        <div class="loading-subtitle" id="loading-overlay-sub">กรุณารอสักครู่</div>

        <div id="loading-overlay-progress-box" class="mt-3 d-none">
            <div class="d-flex justify-content-between align-items-center small mb-1">
                <span class="text-secondary fw-medium" id="loading-overlay-step">0 / 0 ไฟล์</span>
                <span class="fw-bold text-primary tabular-num" id="loading-overlay-pct">0%</span>
            </div>
            <div class="progress" style="height: 8px; border-radius: 9999px; background: #e2e8f0;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="loading-overlay-bar" style="width: 0%;"></div>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm mt-3 px-3 d-none" id="loading-overlay-stop-btn" style="border-radius: 8px;">
                <i class="fa-solid fa-stop me-1"></i> หยุดหลังจบชุดนี้
            </button>
        </div>
    </div>
</div>

<div class="main-wrapper">

    <div class="app-header">
        <div class="app-brand">
            <a href="job.php?job_id=<?= (int)$job['id'] ?>" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;" title="กลับหน้าเลือกงานของ Job">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <span class="header-title">
                <i class="fa-solid fa-folder text-warning me-1"></i> <?= htmlspecialchars($job['name']) ?>
            </span>
            <span class="header-file-pill folder-link" title="คลิกเพื่อเปิดโฟลเดอร์ <?= htmlspecialchars($cnUploadFolderText) ?> ใน Explorer (นับเฉพาะไฟล์ที่ยังไม่ได้ดึงข้อมูล)" onclick="openJobFolder('cn_upload')">
                <i class="fa-regular fa-folder-open me-1 text-primary"></i>
                cn/pdf/ (ใหม่ <strong id="credit-note-upload-count" class="tabular-num text-dark"><?= number_format($cnUploadCount) ?></strong> ไฟล์)
            </span>
        </div>

        <div class="header-tabs">
            <button class="header-tab-btn<?= $initialTab === 'pdf' ? ' active' : '' ?>" id="credit-note-tab-pdf" type="button" data-tab="pdf">
                <i class="fa-solid fa-file-circle-minus cn-accent me-1"></i> 1. ดึงข้อมูลจากใบลดหนี้ (PDF)
            </button>
            <button class="header-tab-btn<?= $initialTab === 'fill' ? ' active' : '' ?>" id="credit-note-tab-fill" type="button" data-tab="fill">
                <i class="fa-solid fa-file-pen text-success me-1"></i> 2. ใส่ยอด PDF ลง ASN
            </button>
            <button class="header-tab-btn<?= $initialTab === 'dashboard' ? ' active' : '' ?>" id="credit-note-tab-dashboard" type="button" data-tab="dashboard">
                <i class="fa-solid fa-chart-pie text-primary me-1"></i> 3. ภาพรวม
            </button>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="header-file-pill"><i class="fa-solid fa-file-circle-minus cn-accent"></i> CREDIT NOTE</span>
        </div>
    </div>

    <div class="dashboard-notice cn-reread-notice d-none" id="credit-note-reread-notice">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>ใบลดหนี้ <strong class="tabular-num" id="credit-note-reread-count">0</strong> ใบดึงข้อมูลไว้ก่อนอัปเดต ยังไม่มีบรรทัดสินค้าและยอดรวมด้านขวา จึงเทียบกับ ASN ไม่ได้ กดปุ่มเพื่ออ่านไฟล์ใน <span class="tabular-num" id="credit-note-reread-folder"><?= htmlspecialchars($cnFinishedText) ?></span> ใหม่ ไฟล์อยู่ที่เดิม</span>
        <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent ms-auto" id="credit-note-reread">
            <i class="fa-solid fa-arrows-rotate me-1"></i> ดึงข้อมูลใหม่จาก finished
        </button>
    </div>

    <div id="credit-note-section" data-step="<?= $cnUploadCount > 0 ? 'extract' : 'idle' ?>" style="<?= creditNoteTabStyle('pdf', $initialTab) ?>">
        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <button class="btn btn-sm px-3 btn-tb btn-tb-accent btn-step-extract" id="credit-note-start" type="button">
                    <i class="fa-solid fa-play me-1"></i> ดึงข้อมูลจากโฟลเดอร์ cn/pdf
                </button>
                <button class="btn btn-outline-danger btn-sm" id="credit-note-stop" type="button" style="display: none;">
                    <i class="fa-solid fa-stop me-1"></i> หยุด
                </button>
                <button class="btn btn-sm btn-tb" id="credit-note-open-upload" type="button" onclick="openJobFolder('cn_upload')" title="เปิดโฟลเดอร์ <?= htmlspecialchars($cnUploadFolderText) ?> ใน Explorer">
                    <i class="fa-regular fa-folder-open me-1"></i> cn/pdf
                </button>
                <button class="btn btn-sm btn-tb" id="credit-note-open-finished" type="button" onclick="openJobFolder('cn_finished')" title="เปิดโฟลเดอร์ CREDIT NOTE/finished ใน Explorer">
                    <i class="fa-regular fa-folder me-1"></i> finished
                </button>

                <div class="modern-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="form-control form-control-sm" id="credit-note-search" placeholder="ค้นหาข้อมูลในตาราง..." autocomplete="off">
                    <button type="button" class="btn-clear" id="credit-note-clear-search" style="display: none;" aria-label="ล้างคำค้นหา"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="toolbar-end d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-tb" id="credit-note-export" type="button" disabled>
                        <i class="fa-solid fa-file-excel me-1"></i> Excel (.xlsx)
                    </button>
                    <button class="btn btn-sm btn-tb btn-tb-quiet" id="credit-note-clear" type="button">
                        <i class="fa-solid fa-rotate-left me-1"></i> ล้างข้อมูลใบลดหนี้
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table class="table mb-0 align-middle" id="credit-note-table">
                <thead>
                    <tr>
                        <?= renderCreditNoteHeader(CREDIT_NOTE_COLUMNS) ?>
                    </tr>
                </thead>
                <tbody id="credit-note-tbody">
                    <?php if ($cnCounts['documents'] === 0): ?>
                        <?= renderCreditNotePlaceholderRows(count(CREDIT_NOTE_COLUMNS)) ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="credit-note-asn-section" data-step="<?= $asnCounts['rows'] > 0 ? 'compare' : 'select' ?>" style="<?= creditNoteTabStyle('asn', $initialTab) ?>">
        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <button class="btn btn-sm px-3 btn-tb btn-tb-green btn-step-select" id="credit-note-asn-folder" type="button" title="อ่านไฟล์ ASN ที่วางไว้ใน <?= htmlspecialchars($cnAsnFolderText) ?>">
                    <i class="fa-regular fa-folder-open me-1"></i> อ่านจากโฟลเดอร์ asn
                </button>
                <input type="file" id="credit-note-asn-file" accept=".xlsx, .xls" multiple class="d-none">
                <button class="btn btn-sm px-3 btn-tb btn-tb-green btn-step-select" id="credit-note-asn-choose" type="button">
                    <i class="fa-solid fa-file-circle-plus me-1"></i> เลือกไฟล์ ASN เพิ่ม (.xlsx)
                </button>

                <span class="header-file-pill toolbar-file-pill" id="credit-note-asn-file-pill" title="ยังไม่ได้เลือกไฟล์ ASN">
                    <i class="fa-regular fa-file-excel"></i>
                    <span id="credit-note-asn-file-text">ยังไม่ได้เลือกไฟล์ ASN</span>
                </span>

                <div id="credit-note-asn-sheet-wrapper" class="d-none align-items-center gap-1">
                    <span class="text-muted small fw-medium text-nowrap">ชีตอื่นของไฟล์นี้:</span>
                    <select id="credit-note-asn-sheet" class="form-select form-select-sm toolbar-sheet-select" aria-label="นำเข้าชีตอื่นของไฟล์ที่เลือกไว้"></select>
                </div>

                <button class="btn btn-sm btn-tb btn-tb-quiet toolbar-end" id="credit-note-asn-clear" type="button">
                    <i class="fa-solid fa-rotate-left me-1"></i> ล้างข้อมูล ASN
                </button>
            </div>

            <div class="toolbar-row">
                <div class="recon-filter-tabs" id="credit-note-asn-filters">
                    <button type="button" class="recon-filter-btn active" data-filter="ALL">
                        ทั้งหมด <span class="recon-count tabular-num" data-cn-count="ALL">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="MATCH" title="ข้อมูล PDF และ ASN ของรายการนี้ตรงกันครบ">
                        <i class="fa-solid fa-circle-check"></i>สมบูรณ์ <span class="recon-count tabular-num" data-cn-count="MATCH">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="MISMATCH" title="ข้อมูลไม่ตรงกับ ASN เช่น จำนวนไม่ตรง ยอดเงินไม่ตรง บาร์โค้ดมีฝั่งเดียว หรือไม่พบใบลดหนี้ใน PDF">
                        <i class="fa-solid fa-triangle-exclamation"></i>ผิดพลาด <span class="recon-count tabular-num" data-cn-count="MISMATCH">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="PENDING" title="นำเข้า ASN แล้ว ยังไม่ได้ใส่ยอดจาก PDF ลงไฟล์ ASN">
                        <i class="fa-solid fa-clock"></i>รอเทียบ <span class="recon-count tabular-num" data-cn-count="PENDING">0</span>
                    </button>
                </div>

                <div class="modern-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="form-control form-control-sm" id="credit-note-asn-search" placeholder="ค้นหาข้อมูลในตาราง..." autocomplete="off">
                    <button type="button" class="btn-clear" id="credit-note-asn-clear-search" style="display: none;" aria-label="ล้างคำค้นหา"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="toolbar-end d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-tb" id="credit-note-asn-export" type="button" disabled>
                        <i class="fa-solid fa-file-excel me-1"></i> Excel (.xlsx)
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table class="table mb-0 align-middle" id="credit-note-asn-table">
                <thead>
                    <tr>
                        <?= renderCreditNoteHeader(CN_COMPARE_COLUMNS) ?>
                    </tr>
                </thead>
                <tbody id="credit-note-asn-tbody">
                    <?php if ($asnCounts['rows'] === 0): ?>
                        <?= renderCreditNotePlaceholderRows(count(CN_COMPARE_COLUMNS)) ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="credit-note-dash-section" style="<?= creditNoteTabStyle('dashboard', $initialTab) ?>">
        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <span class="dash-state is-muted" id="credit-note-dash-state">
                    <i class="fa-solid fa-circle-notch fa-spin"></i> กำลังโหลด
                </span>
                <span class="dash-updated" id="credit-note-dash-updated"></span>
                <button type="button" class="btn btn-sm px-3 btn-tb toolbar-end" id="credit-note-dash-refresh">
                    <i class="fa-solid fa-rotate me-1"></i> รีเฟรช
                </button>
            </div>
        </div>

        <div class="dashboard-scroll">
            <div class="dashboard-wrap">
                <div id="credit-note-dash-notice" class="dashboard-notice d-none">
                    <i class="fa-solid fa-circle-info" id="credit-note-dash-notice-icon"></i>
                    <span id="credit-note-dash-notice-text"></span>
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent ms-auto" id="credit-note-dash-notice-action">
                        <i class="fa-solid fa-file-pen me-1"></i> ไปแท็บใส่ยอด PDF ลง ASN
                    </button>
                </div>

                <section class="dash-hero">
                    <div class="dash-hero-top">
                        <span class="dash-icon" style="--tone-bg: #f1f5f9; --tone-fg: #475569;"><i class="fa-solid fa-hashtag"></i></span>
                        <div class="dash-hero-title">
                            <div class="dash-label">ใบลดหนี้ทั้งหมด</div>
                            <div class="dash-hint">เลขใบลดหนี้ในไฟล์ ASN ที่นำเข้าในแท็บ 2 นับเป็นใบ ไม่ใช่จำนวนแถว</div>
                        </div>
                        <div class="dash-number dash-number-hero tabular-num" id="credit-note-dash-total">–</div>
                    </div>
                    <div class="dash-bar is-empty" id="credit-note-dash-bar" role="img" aria-label="สัดส่วนสถานะของใบลดหนี้">
                        <span class="dash-seg" data-seg="complete" style="--seg: #22c55e;"></span>
                        <span class="dash-seg" data-seg="pending" style="--seg: var(--status-waiting-color);"></span>
                        <span class="dash-seg" data-seg="error" style="--seg: #ef4444;"></span>
                    </div>
                    <ul class="dash-legend">
                        <li><i class="dash-dot" style="--seg: #22c55e;"></i>พบใน PDF <b class="tabular-num" data-cn-legend="complete">–</b></li>
                        <li><i class="dash-dot" style="--seg: var(--status-waiting-color);"></i>ยังไม่อ่านยอด <b class="tabular-num" data-cn-legend="pending">–</b></li>
                        <li><i class="dash-dot" style="--seg: #ef4444;"></i>ไม่พบใน PDF <b class="tabular-num" data-cn-legend="error">–</b></li>
                    </ul>
                </section>

                <div class="dash-grid">
                    <article class="dash-panel" style="--tone-bg: #dcfce7; --tone-bd: #bbf7d0; --tone-fg: var(--status-match-color);">
                        <header class="dash-panel-head">
                            <span class="dash-icon"><i class="fa-solid fa-circle-check"></i></span>
                            <span class="dash-label">พบใน PDF</span>
                            <span class="dash-pct tabular-num" data-cn-pct="complete">–</span>
                        </header>
                        <div class="dash-number tabular-num" id="credit-note-dash-complete">–</div>
                        <div class="dash-hint">ใส่ยอดจาก PDF แล้ว</div>
                    </article>

                    <article class="dash-panel" style="--tone-bg: #e0f2fe; --tone-bd: #bae6fd; --tone-fg: var(--status-waiting-color);">
                        <header class="dash-panel-head">
                            <span class="dash-icon"><i class="fa-solid fa-clock"></i></span>
                            <span class="dash-label">ยังไม่อ่านยอด</span>
                            <span class="dash-pct tabular-num" data-cn-pct="pending">–</span>
                        </header>
                        <div class="dash-number tabular-num" id="credit-note-dash-pending">–</div>
                        <div class="dash-hint">นำเข้า ASN แล้ว ยังไม่ได้กดอ่านยอดจาก PDF</div>
                    </article>

                    <article class="dash-panel" style="--tone-bg: #fee2e2; --tone-bd: #fecaca; --tone-fg: var(--status-mismatch-color);">
                        <header class="dash-panel-head">
                            <span class="dash-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                            <span class="dash-label">ไม่พบใน PDF</span>
                            <span class="dash-pct tabular-num" data-cn-pct="error">–</span>
                        </header>
                        <div class="dash-number tabular-num" id="credit-note-dash-error">–</div>
                        <div class="dash-hint">เลขใบลดหนี้นี้ไม่มีในใบลดหนี้ PDF ที่ดึงข้อมูลไว้</div>
                    </article>
                </div>

                <section class="dash-table-panel">
                    <header class="dash-table-head">
                        <span class="dash-icon" style="--tone-bg: #f1f5f9; --tone-fg: #475569;"><i class="fa-solid fa-table-list"></i></span>
                        <div class="dash-hero-title">
                            <div class="dash-label">สรุปรายใบลดหนี้</div>
                            <div class="dash-hint">1 ใบลดหนี้ 1 แถว ตามไฟล์ ASN ที่นำเข้าในแท็บ 2 จำนวนชิ้นและ Cost Ex VAT มาจาก ASN ยอด PDF และจำนวนหน้ามาจากใบลดหนี้ PDF</div>
                        </div>
                        <span class="dash-pct tabular-num" id="credit-note-dash-count" style="--tone-bg: #f1f5f9; --tone-bd: #e2e8f0; --tone-fg: #475569;">–</span>
                        <div class="toolbar-row dash-table-tools">
                            <div class="recon-filter-tabs" id="credit-note-dash-filters">
                                <button type="button" class="recon-filter-btn active" data-filter="ALL">
                                    ทั้งหมด <span class="recon-count tabular-num" data-cn-dash-count="ALL">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="MATCH" title="ใส่ยอดจาก PDF แล้ว">
                                    <i class="fa-solid fa-circle-check"></i>พบใน PDF <span class="recon-count tabular-num" data-cn-dash-count="MATCH">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="MISMATCH" title="ไม่พบเลขใบลดหนี้นี้ใน PDF">
                                    <i class="fa-solid fa-triangle-exclamation"></i>ไม่พบใน PDF <span class="recon-count tabular-num" data-cn-dash-count="MISMATCH">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="PENDING" title="ยังไม่ได้กดอ่านยอดจาก PDF">
                                    <i class="fa-solid fa-clock"></i>ยังไม่อ่านยอด <span class="recon-count tabular-num" data-cn-dash-count="PENDING">0</span>
                                </button>
                            </div>

                            <div class="modern-search">
                                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                                <input type="text" class="form-control form-control-sm" id="credit-note-dash-search" placeholder="ค้นหาข้อมูลในตาราง..." autocomplete="off">
                                <button type="button" class="btn-clear" id="credit-note-dash-clear-search" style="display: none;" aria-label="ล้างคำค้นหา"><i class="fa-solid fa-xmark"></i></button>
                            </div>

                            <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-green toolbar-end" id="credit-note-dash-export" disabled>
                                <i class="fa-solid fa-file-excel me-1"></i> Excel (.xlsx)
                            </button>
                        </div>
                    </header>
                    <div class="dash-table-scroll">
                        <table class="table mb-0 align-middle" id="credit-note-dash-table">
                            <thead>
                                <tr>
                                    <?= renderCreditNoteHeader(CN_DASH_COLUMNS, 'store_no') ?>
                                </tr>
                            </thead>
                            <tbody id="credit-note-dash-tbody"></tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div id="credit-note-fill-section" data-step="<?= $fillCounts['rows'] > 0 ? 'fill' : 'select' ?>" style="<?= creditNoteTabStyle('fill', $initialTab) ?>">
        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <input type="file" id="credit-note-fill-file" accept=".xlsx" class="d-none">
                <button class="btn btn-sm px-3 btn-tb btn-tb-green btn-step-select" id="credit-note-fill-choose" type="button" title="เลือกไฟล์ ASN (.xlsx) แล้วเลือกชีตที่ต้องใช้ ระบบเก็บไฟล์และทุกคอลัมน์ของชีตลงฐานข้อมูล">
                    <i class="fa-solid fa-file-circle-plus me-1"></i> นำเข้าไฟล์ ASN (.xlsx)
                </button>

                <span class="header-file-pill toolbar-file-pill" id="credit-note-fill-file-pill" title="ยังไม่ได้นำเข้าไฟล์ ASN">
                    <i class="fa-regular fa-file-excel"></i>
                    <span id="credit-note-fill-file-text">ยังไม่ได้นำเข้าไฟล์ ASN</span>
                </span>

                <button class="btn btn-sm px-3 btn-tb btn-step-compare" id="credit-note-fill-read" type="button"<?= $fillCounts['rows'] > 0 ? '' : ' disabled' ?> title="อ่าน Total Amt Excluding VAT และ VAT (7%) จากใบลดหนี้ PDF ที่ดึงข้อมูลไว้ในแท็บ 1 แล้วใส่ให้ทุกแถวที่เลขใบลดหนี้ตรงกัน">
                    <i class="fa-solid fa-file-import me-1"></i> อ่านยอดจาก PDF
                </button>

                <button class="btn btn-sm btn-tb btn-tb-quiet toolbar-end" id="credit-note-fill-clear" type="button">
                    <i class="fa-solid fa-rotate-left me-1"></i> ล้างข้อมูล
                </button>
            </div>

            <div class="toolbar-row">
                <div class="recon-filter-tabs" id="credit-note-fill-filters">
                    <button type="button" class="recon-filter-btn active" data-filter="ALL">
                        ทั้งหมด <span class="recon-count tabular-num" data-cn-fill-count="ALL">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="MATCH" title="พบใบลดหนี้ใน PDF ใส่ยอดแล้ว">
                        <i class="fa-solid fa-circle-check"></i>พบใน PDF <span class="recon-count tabular-num" data-cn-fill-count="MATCH">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="MISMATCH" title="ไม่พบเลขใบลดหนี้นี้ใน PDF ที่ดึงข้อมูลไว้">
                        <i class="fa-solid fa-triangle-exclamation"></i>ไม่พบใน PDF <span class="recon-count tabular-num" data-cn-fill-count="MISMATCH">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="PENDING" title="นำเข้าแล้ว ยังไม่ได้กดอ่านยอดจาก PDF">
                        <i class="fa-solid fa-clock"></i>ยังไม่อ่านยอด <span class="recon-count tabular-num" data-cn-fill-count="PENDING">0</span>
                    </button>
                </div>

                <div class="modern-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="form-control form-control-sm" id="credit-note-fill-search" placeholder="ค้นหาข้อมูลในตาราง..." autocomplete="off">
                    <button type="button" class="btn-clear" id="credit-note-fill-clear-search" style="display: none;" aria-label="ล้างคำค้นหา"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="toolbar-end d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-tb" id="credit-note-fill-export-missing" type="button" disabled title="รายการแถวที่ไม่พบเลขใบลดหนี้ใน PDF">
                        <i class="fa-solid fa-triangle-exclamation me-1 text-danger"></i> Export ไม่พบใน PDF
                    </button>
                    <button class="btn btn-sm btn-tb btn-tb-green" id="credit-note-fill-export" type="button" disabled title="ดาวน์โหลดไฟล์ ASN ต้นฉบับที่ต่อคอลัมน์ PDF_TOTAL_COST_EX_VAT และ PDF_TOTAL_VAT ท้ายชีตที่นำเข้า (ชื่อลงท้าย _PDF_Amount.xlsx)">
                        <i class="fa-solid fa-file-excel me-1"></i> Export ASN (.xlsx)
                    </button>
                </div>
            </div>
        </div>

        <div class="dashboard-notice cn-fill-missing d-none" id="credit-note-fill-missing">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div class="flex-grow-1">
                <div><strong>ไม่พบใน PDF <span class="tabular-num" id="credit-note-fill-missing-docs">0</span> ใบ</strong> (<span class="tabular-num" id="credit-note-fill-missing-rows">0</span> แถว) เลขใบลดหนี้เหล่านี้ไม่มีในใบลดหนี้ PDF ที่ดึงข้อมูลไว้ในแท็บ 1</div>
                <div class="cn-fill-missing-list tabular-num" id="credit-note-fill-missing-list"></div>
            </div>
            <button type="button" class="btn btn-sm px-3 btn-tb" id="credit-note-fill-missing-show">
                <i class="fa-solid fa-list me-1"></i> ดูรายการ
            </button>
        </div>

        <div class="table-container">
            <table class="table mb-0 align-middle" id="credit-note-fill-table">
                <thead>
                    <tr>
                        <?= renderCreditNoteHeader(CN_FILL_COLUMNS) ?>
                    </tr>
                </thead>
                <tbody id="credit-note-fill-tbody">
                    <?php if ($fillCounts['rows'] === 0): ?>
                        <?= renderCreditNotePlaceholderRows(count(CN_FILL_COLUMNS)) ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="app-statusbar">
        <div class="d-flex align-items-center gap-3" id="credit-note-status-counts">
            <div class="text-nowrap">
                <i class="fa-regular fa-file-lines text-primary me-1"></i>
                ไฟล์: <strong id="credit-note-files-count" class="tabular-num text-dark"><?= number_format($cnCounts['files']) ?></strong>
            </div>
            <span class="text-muted">|</span>
            <div class="text-nowrap">
                <i class="fa-solid fa-receipt cn-accent me-1"></i>
                ใบลดหนี้: <strong id="credit-note-docs-count" class="tabular-num text-dark"><?= number_format($cnCounts['documents']) ?></strong>
            </div>
            <span class="text-muted d-none">|</span>
            <div class="text-nowrap d-none" id="credit-note-asn-sheets-item">
                <i class="fa-solid fa-file-excel text-success me-1"></i>
                ASN: <strong id="credit-note-asn-sheets-count" class="tabular-num text-dark"><?= number_format($asnCounts['files']) ?></strong> ชีต
            </div>
            <span class="text-muted d-none">|</span>
            <div class="text-nowrap d-none">
                <i class="fa-solid fa-list-check text-success me-1"></i>
                รายการ: <strong id="credit-note-asn-rows-count" class="tabular-num text-dark"><?= number_format($asnCounts['rows']) ?></strong>
            </div>
        </div>
        <a href="#" class="folder-link small" title="คลิกเพื่อเปิดโฟลเดอร์ CREDIT NOTE ของ Job นี้ใน Explorer" onclick="openJobFolder('cn'); return false;">
            <i class="fa-regular fa-folder-closed me-1 text-primary"></i>
            โฟลเดอร์: <span class="tabular-num"><?= htmlspecialchars(JobFolders::displayPath($jobManager->getJobCreditNoteDir($job, false))) ?>/</span>
        </a>
    </div>

</div>

<div class="modal fade" id="creditNoteScanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-copy text-primary me-2"></i>ตรวจสอบไฟล์ในโฟลเดอร์ cn/pdf
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="small text-secondary mb-2">
                    ไฟล์ PDF ในโฟลเดอร์ cn/pdf ทั้งหมด <strong class="tabular-num text-dark" id="creditNoteScanTotal">0</strong> ไฟล์ บางไฟล์เคยดึงข้อมูลเข้า Job นี้แล้ว
                </div>
                <div class="list-group mb-3">
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div><i class="fa-solid fa-file-circle-plus text-success me-2"></i>ไฟล์ใหม่ (ยังไม่เคยดึงเข้า Job นี้)</div>
                        <span class="badge bg-success rounded-pill tabular-num fs-6" id="creditNoteScanNew">0</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center" id="creditNoteScanExistingRow">
                        <div><i class="fa-solid fa-file-circle-check text-warning me-2"></i>ไฟล์เดิม (ชื่อไฟล์นี้เคยดึงเข้า Job นี้แล้ว)</div>
                        <span class="badge bg-warning text-dark rounded-pill tabular-num fs-6" id="creditNoteScanExisting">0</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center" id="creditNoteScanDuplicateRow">
                        <div>
                            <i class="fa-solid fa-copy text-danger me-2"></i>ไฟล์ใหม่ที่เลขใบลดหนี้ซ้ำกับไฟล์อื่นใน Job นี้
                            <div class="small text-muted mt-1" id="creditNoteScanDuplicateDetail"></div>
                        </div>
                        <span class="badge bg-danger rounded-pill tabular-num fs-6" id="creditNoteScanDuplicate">0</span>
                    </div>
                </div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>ข้าม: ดึงเฉพาะไฟล์ใหม่ ไฟล์ที่ซ้ำไม่ถูกอ่าน แทนที่: ดึงทุกไฟล์ แล้วใช้ข้อมูลจากไฟล์ใหม่แทนข้อมูลเดิมของเลขใบลดหนี้เดียวกัน</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-between gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent" id="creditNoteScanSkip">
                        <i class="fa-solid fa-forward me-1"></i> ข้ามไฟล์ที่ซ้ำ
                    </button>
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-quiet" id="creditNoteScanReplace">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> แทนที่ด้วยไฟล์ใหม่
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteNoticeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-info text-primary" id="creditNoteNoticeIcon"></i>
                    <span id="creditNoteNoticeTitle">แจ้งเตือน</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fw-semibold text-dark mb-2" id="creditNoteNoticeMessage"></div>
                <div class="cn-notice-detail" id="creditNoteNoticeDetail"></div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb d-none" id="creditNoteNoticeAction"></button>
                <button type="button" class="btn btn-primary btn-sm px-4 fw-semibold" data-bs-dismiss="modal" style="border-radius: 8px;">ตกลง</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteClearModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-rotate-left text-danger me-2"></i>ล้างข้อมูลใบลดหนี้
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope" style="background: #fef2f2; border-color: #fecaca;">
                    <div class="fix-scope-icon" style="background: #fee2e2; color: #b91c1c;"><i class="fa-solid fa-trash-can"></i></div>
                    <div>
                        <div class="fix-scope-title">ลบใบลดหนี้ <span class="tabular-num" id="creditNoteClearDocs">0</span> ใบ จาก <span class="tabular-num" id="creditNoteClearFiles">0</span> ไฟล์ ออกจาก Job นี้</div>
                        <div class="fix-scope-detail">ตารางจะว่าง และนับใหม่ตั้งแต่ศูนย์ ข้อมูล ASN ยังอยู่ ต้องเทียบกับ PDF ใหม่หลังดึงข้อมูลอีกครั้ง</div>
                    </div>
                </div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>ไฟล์ PDF ไม่ถูกลบ ไฟล์ต้นฉบับยังอยู่ในโฟลเดอร์ <?= htmlspecialchars($cnUploadFolderText) ?> ถ้าต้องการดึงข้อมูลใหม่ กดดึงข้อมูลอีกครั้ง</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-danger btn-sm px-3 fw-semibold" id="creditNoteClearConfirm" style="border-radius: 8px;">
                    <i class="fa-solid fa-trash-can me-1"></i> ล้างข้อมูล
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteAsnImportModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-file-excel text-success me-2"></i>ยืนยันการดึงข้อมูลไฟล์ ASN
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="cn-import-icon"><i class="fa-solid fa-cloud-arrow-down"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark fs-6" id="creditNoteAsnImportTitle">เลือกไฟล์ ASN 1 ไฟล์</div>
                        <div class="text-secondary small" id="creditNoteAsnImportSub">เลือกชีตที่เป็นใบลดหนี้ แล้วกดยืนยัน</div>
                    </div>
                </div>
                <div class="bg-light p-3 rounded-3 border mb-0" id="creditNoteAsnImportDetails"></div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-success btn-sm px-3 fw-semibold" id="creditNoteAsnImportConfirm" style="border-radius: 8px;">
                    <i class="fa-solid fa-check me-1"></i> ยืนยันดึงข้อมูลลงตาราง
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteAsnDupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-copy text-danger me-2"></i>พบเลขใบลดหนี้ซ้ำกับชีต ASN อื่น
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope" style="background: #fef2f2; border-color: #fecaca;">
                    <div class="fix-scope-icon" style="background: #fee2e2; color: #b91c1c;"><i class="fa-solid fa-file-circle-exclamation"></i></div>
                    <div>
                        <div class="fix-scope-title"><span id="creditNoteAsnDupCount" class="tabular-num">0</span> ใบลดหนี้มีอยู่แล้ว</div>
                        <div class="fix-scope-detail" id="creditNoteAsnDupFiles"></div>
                    </div>
                </div>
                <div class="small text-secondary mb-2">ตัวอย่างเลขใบลดหนี้: <span class="tabular-num" id="creditNoteAsnDupSample"></span></div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>ถ้านำเข้าทั้งสองชุด จำนวนสินค้าของใบลดหนี้เหล่านี้จะถูกนับซ้ำและขึ้นเป็นผิดพลาด จึงมี 2 ทางเลือก ข้ามใบที่ซ้ำ (ใช้ข้อมูลเดิม) หรือแทนที่ด้วยข้อมูลจากชีตใหม่</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-between gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent" id="creditNoteAsnDupSkip">
                        <i class="fa-solid fa-forward me-1"></i> ข้ามใบที่ซ้ำ
                    </button>
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-quiet" id="creditNoteAsnDupReplace">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> แทนที่ด้วยชีตใหม่
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteAsnSameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>ชีต ASN นี้เคยนำเข้าแล้ว
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope" style="background: #fffbeb; border-color: #fde68a;">
                    <div class="fix-scope-icon" style="background: #fef3c7; color: #b45309;"><i class="fa-regular fa-file-excel"></i></div>
                    <div>
                        <div class="fix-scope-title" id="creditNoteAsnSameLabel"></div>
                        <div class="fix-scope-detail">
                            ในระบบ <span id="creditNoteAsnSameStored" class="tabular-num">0</span> แถว ชีตที่เลือก <span id="creditNoteAsnSameIncoming" class="tabular-num">0</span> แถว
                        </div>
                        <div class="fix-scope-detail fw-semibold" id="creditNoteAsnSameVerdict"></div>
                    </div>
                </div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span id="creditNoteAsnSameNote" style="white-space: pre-line;"></span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-between gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent" id="creditNoteAsnSameSkip">
                        <i class="fa-solid fa-forward me-1"></i> ข้าม (ใช้ข้อมูลเดิม)
                    </button>
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-quiet" id="creditNoteAsnSameReplace">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> เขียนทับ
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteCompareModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-scale-balanced text-primary me-2"></i>ยืนยันการเปรียบเทียบข้อมูลกับ PDF
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="cn-import-icon cn-compare-icon"><i class="fa-solid fa-code-compare"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark fs-6">ตรวจสอบและเปรียบเทียบข้อมูล</div>
                        <div class="text-secondary small">ระบบจะจับคู่ Credit Note No. และ Barcode แล้วเทียบ Qty ยอดเงิน และ VAT <?= htmlspecialchars(CN_AMOUNT_RULE) ?> ยอดเงิน<span data-cn-rule="amount"></span> และ VAT <span data-cn-rule="vat"></span></div>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-3 border mb-3">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-secondary small"><i class="fa-solid fa-file-circle-minus cn-accent me-1"></i> ใบลดหนี้จาก PDF:</span>
                        <strong class="tabular-num text-dark" id="creditNoteComparePdfCount">0 ใบ</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 pt-2">
                        <span class="text-secondary small"><i class="fa-solid fa-file-excel text-success me-1"></i> ข้อมูลจาก ASN:</span>
                        <strong class="tabular-num text-dark" id="creditNoteCompareAsnCount">0 รายการ</strong>
                    </div>
                </div>
                <div class="fix-note d-none" id="creditNoteCompareRereadNote">
                    <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                    <span id="creditNoteCompareRereadText"></span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-success btn-sm px-3 fw-semibold" id="creditNoteCompareConfirm" style="border-radius: 8px;">
                    <i class="fa-solid fa-play me-1"></i> ยืนยันเริ่มเปรียบเทียบ
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteAsnClearModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-trash-can text-danger me-2"></i>ล้างข้อมูล ASN
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small text-secondary">เลือกชีตที่ต้องการลบออกจาก Job นี้</span>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="creditNoteAsnClearAll">
                        <label class="form-check-label small" for="creditNoteAsnClearAll">เลือกทั้งหมด</label>
                    </div>
                </div>
                <div class="list-group mb-3" id="creditNoteAsnClearList"></div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>ลบเฉพาะแถว ASN ของชีตที่เลือก ใบลดหนี้ PDF และไฟล์ Excel ไม่ถูกลบ ถ้าเคยเทียบกับ PDF แล้ว ระบบเทียบใหม่กับ ASN ที่เหลือให้ทันที</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-danger btn-sm px-3 fw-semibold" id="creditNoteAsnClearConfirm" style="border-radius: 8px;" disabled>
                    <i class="fa-solid fa-trash-can me-1"></i> ลบชีตที่เลือก (<span class="tabular-num" id="creditNoteAsnClearCount">0</span>)
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditNoteFillImportModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-file-excel text-success me-2"></i>เลือกชีตที่จะนำเข้า
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="cn-import-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark fs-6 text-break" id="creditNoteFillImportTitle"></div>
                        <div class="text-secondary small">เลือกได้หลายชีต ระบบเก็บไฟล์ต้นฉบับและทุกคอลัมน์ของชีตที่เลือกลงฐานข้อมูล ชีตที่ไม่มีคอลัมน์เลขใบลดหนี้และบาร์โค้ดเลือกไม่ได้</div>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small text-secondary">ชีตในไฟล์</span>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="creditNoteFillImportAll">
                        <label class="form-check-label small" for="creditNoteFillImportAll">เลือกทุกชีตที่มีข้อมูล</label>
                    </div>
                </div>
                <div class="list-group cn-fill-sheet-list mb-3" id="creditNoteFillImportList"></div>
                <div class="fix-note d-none" id="creditNoteFillImportReplace">
                    <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                    <span>ไฟล์ชื่อนี้เคยนำเข้าแล้ว ข้อมูลเดิมของไฟล์นี้ (ทุกชีตและยอดที่อ่านจาก PDF) จะถูกแทนที่ด้วยชีตที่เลือกตอนนี้ ชีตที่เคยนำเข้าถูกเลือกไว้ให้แล้ว</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-success btn-sm px-3 fw-semibold" id="creditNoteFillImportConfirm" style="border-radius: 8px;" disabled>
                    <i class="fa-solid fa-check me-1"></i> นำเข้า <span class="tabular-num" id="creditNoteFillImportCount">0</span> ชีต
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/xlsx.full.min.js?v=<?= filemtime(__DIR__ . '/assets/vendor/xlsx.full.min.js') ?>" async></script>
<script>
    const CURRENT_JOB_ID = <?= (int)$job['id'] ?>;
    const CN_JOB_NAME_CLEAN = <?= json_encode(preg_replace('/[^A-Za-z0-9_\-]/', '_', $job['name']), JSON_UNESCAPED_UNICODE) ?>;
    const CN_COLUMNS = <?= json_encode(CREDIT_NOTE_COLUMNS, JSON_UNESCAPED_UNICODE) ?>;
    const CN_COMPARE_COLUMNS = <?= json_encode(CN_COMPARE_COLUMNS, JSON_UNESCAPED_UNICODE) ?>;
    const CN_DASH_COLUMNS = <?= json_encode(CN_DASH_COLUMNS, JSON_UNESCAPED_UNICODE) ?>;
    const CN_FILL_COLUMNS = <?= json_encode(CN_FILL_COLUMNS, JSON_UNESCAPED_UNICODE) ?>;
    const CN_TOLERANCES = <?= json_encode(['amount' => CreditNoteReconciler::AMOUNT_TOLERANCE, 'vat' => CreditNoteReconciler::VAT_TOLERANCE]) ?>;
</script>
<script src="assets/js/ui-utils.js?v=<?= filemtime(__DIR__ . '/assets/js/ui-utils.js') ?>"></script>
<?php foreach (['table', 'extraction', 'asn-sheets', 'compare-table', 'compare', 'asn-fill', 'dashboard', 'page'] as $module): ?>
<script src="assets/js/credit-note/<?= $module ?>.js?v=<?= filemtime(__DIR__ . '/assets/js/credit-note/' . $module . '.js') ?>"></script>
<?php endforeach; ?>
<script src="assets/js/workspace/column-highlight.js?v=<?= filemtime(__DIR__ . '/assets/js/workspace/column-highlight.js') ?>"></script>
</body>
</html>
