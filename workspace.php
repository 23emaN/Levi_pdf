<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\AsnReconciler;
use App\FileManager;
use App\JobFolders;
use App\JobManager;

$jobId = intval($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    header('Location: index.php');
    exit;
}
$initialTab = in_array($_GET['tab'] ?? '', ['asn', 'dashboard'], true) ? $_GET['tab'] : 'pdf';

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

$asnStep = $jobManager->initialAsnStep($job);
$pendingFixCount = $asnStep === 'fix' ? (int)$job['asn_pending_fix_count'] : 0;
$uploadDir = $jobManager->getJobUploadDir($job, false);
$uploadCount = is_dir($uploadDir) ? count(FileManager::getPdfFiles($uploadDir)) : 0;
$statusCounts = $jobManager->statusCounts($jobId);

function renderPdfEmptyPlaceholderRows(int $rowCount = 18): string {
    $out = '';
    $emptyCells = str_repeat('<td></td>', 26);
    for ($i = 1; $i <= $rowCount; $i++) {
        $out .= "<tr class=\"placeholder-grid-row\"><td class=\"text-center text-muted tabular-num opacity-40\">{$i}</td>{$emptyCells}</tr>";
    }
    return $out;
}

const AMOUNT_RULE = 'ยอดก่อน VAT ของ PDF (Amt Excl VAT), ASN (Cost Ex VAT) และ VBRP (Subtotal 4) ต่างกันได้ไม่เกิน ' . AsnReconciler::AMOUNT_TOLERANCE . ' บาท แหล่งที่ไม่ตรงกับแหล่งอื่นเลยถือว่าผิด บิลที่ไม่มีใน VBRP เทียบแค่ PDF กับ ASN';
const VERDICT_RULE = 'ผลของแหล่งนี้: ✓ ตรงกับอีกแหล่งอย่างน้อยหนึ่งแหล่ง, ✗ ไม่ตรงกับแหล่งใด, - ไม่ได้ตัดสิน (มีค่าให้เทียบไม่ถึงสองแหล่ง หรือบิลไม่มีใน VBRP) สถานะของบิลตาม PDF ยกเว้นบิลที่มีใน VBRP ซึ่ง ASN ไม่ตรง บิลนั้นรอแก้ไข ASN';
const VBRP_SKIP_NOTE = 'แถว VBRP ที่ Subtotal 3 (ช่อง CJ) ตั้งแต่ -1 ขึ้นไปไม่นำมาคิด';
const PENDING_ASN_HINT = 'ASN ไม่ตรง แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่ ปุ่มแก้ไขและสั่งพิมพ์ใช้ไม่ได้จนกว่าจะตรง';
const VBRP_PILL_TITLE ='ระบบไม่เก็บไฟล์ VBRP เก็บเฉพาะแถวตามเลขบิล นำเข้าไฟล์ที่มีบิลเดิมจะแทนที่แถวของบิลนั้น ' . VBRP_SKIP_NOTE;

const ASN_COLUMNS = [
    ['key' => '#', 'label' => '#', 'type' => 'number', 'class' => 'text-center'],
    ['key' => 'status', 'label' => 'Status', 'type' => 'number', 'class' => 'text-center'],
    ['key' => 'invoice_no', 'label' => 'Tax Invoice No.', 'type' => 'string'],
    ['key' => 'printed', 'label' => 'Print', 'type' => 'number', 'class' => 'text-center', 'width' => 110, 'title' => 'สั่งพิมพ์ไปแล้วในรอบไหน'],
    ['key' => 'source_file', 'label' => 'Source File', 'type' => 'string', 'width' => 440, 'title' => 'ไฟล์ PDF ต้นฉบับที่มีหน้าของบิลนี้'],
    ['key' => 'barcode', 'label' => 'Barcode / EAN', 'type' => 'string', 'width' => 140],
    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'width' => 120],
    ['key' => 'pdf_branch', 'label' => 'Store (PDF)', 'type' => 'string', 'class' => 'text-center', 'width' => 110],
    ['key' => 'asn_store', 'label' => 'Store (ASN)', 'type' => 'string', 'class' => 'text-center', 'width' => 105],
    ['key' => 'pdf_qty', 'label' => 'Qty (PDF)', 'type' => 'number', 'class' => 'text-center', 'width' => 95],
    ['key' => 'asn_qty', 'label' => 'Qty (ASN)', 'type' => 'number', 'class' => 'text-center', 'width' => 110],
    ['key' => 'vbrp_qty', 'label' => 'Qty (VBRP)', 'type' => 'number', 'class' => 'text-center', 'width' => 110, 'title' => 'Billed Quantity รวมของบาร์โค้ดนี้ในบิล ' . VBRP_SKIP_NOTE],
    ['key' => 'pdf_price', 'label' => 'RSP Price (PDF)', 'type' => 'currency', 'class' => 'text-end', 'width' => 120],
    ['key' => 'asn_price', 'label' => 'Sell Price (ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 130],
    ['key' => 'vbrp_price', 'label' => 'Sell Price (VBRP)', 'type' => 'currency', 'class' => 'text-end', 'width' => 140, 'title' => 'Subtotal 2 ต่ำสุดของบาร์โค้ดนี้ในบิล แสดงอย่างเดียว ไม่ได้เทียบกับแหล่งอื่น ' . VBRP_SKIP_NOTE],
    ['key' => 'pdf_amt', 'label' => 'Amt Excl VAT (PDF)', 'type' => 'currency', 'class' => 'text-end', 'width' => 135, 'title' => AMOUNT_RULE],
    ['key' => 'asn_amt', 'label' => 'Cost Ex VAT (ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 135, 'title' => AMOUNT_RULE],
    ['key' => 'vbrp_amt', 'label' => 'Amt Excl VAT (VBRP)', 'type' => 'currency', 'class' => 'text-end', 'width' => 150, 'title' => 'Subtotal 4 รวมทั้งบิล ' . AMOUNT_RULE],
    ['key' => 'vat_amt', 'label' => 'VAT (PDF / ASN)', 'type' => 'currency', 'class' => 'text-end', 'width' => 135],
    ['key' => 'vbrp_vat', 'label' => 'VAT (VBRP)', 'type' => 'currency', 'class' => 'text-end', 'width' => 110, 'title' => 'Tax Amount รวมทั้งบิล'],
    ['key' => 'verdict_pdf', 'label' => 'PDF', 'type' => 'number', 'class' => 'text-center', 'width' => 72, 'title' => VERDICT_RULE],
    ['key' => 'verdict_asn', 'label' => 'ASN', 'type' => 'number', 'class' => 'text-center', 'width' => 72, 'title' => VERDICT_RULE],
    ['key' => 'verdict_vbrp', 'label' => 'VBRP', 'type' => 'number', 'class' => 'text-center', 'width' => 76, 'title' => VERDICT_RULE],
    ['key' => 'remark', 'label' => 'Remark', 'type' => 'string', 'width' => 520, 'title' => 'ปัญหาและวิธีแก้ไข บรรทัดสีเทาเป็นหมายเหตุ: แหล่งอื่นที่ไม่ตรง (ASN หรือ VBRP) สิ่งที่ PDF ยังต้องแก้หลังแก้ ASN และสถานะเดิมของบิล (เดิม: ...)'],
    ['key' => 'manage', 'label' => 'Manage', 'class' => 'text-center', 'width' => 105],
];

function renderAsnHeaderCells(): string {
    $out = '';
    foreach (ASN_COLUMNS as $col) {
        $attributes = ' data-col="' . htmlspecialchars($col['key']) . '"'
            . (isset($col['type']) ? ' data-type="' . $col['type'] . '"' : '')
            . (isset($col['class']) ? ' class="' . $col['class'] . '"' : '')
            . (isset($col['width']) ? ' style="width: ' . $col['width'] . 'px;"' : '')
            . (isset($col['title']) ? ' title="' . htmlspecialchars($col['title']) . '"' : '');
        $sortIcon = isset($col['type']) ? ' <i class="fa-solid fa-sort sort-icon"></i>' : '';
        $out .= "<th{$attributes}>" . htmlspecialchars($col['label']) . "{$sortIcon}</th>\n";
    }
    return $out;
}

function renderReconEmptyPlaceholderRows(int $rowCount = 18): string {
    $out = '';
    $emptyCells = str_repeat('<td></td>', count(ASN_COLUMNS) - 1);
    for ($i = 1; $i <= $rowCount; $i++) {
        $out .= "<tr class=\"placeholder-grid-row\"><td class=\"text-center text-muted tabular-num opacity-40\">{$i}</td>{$emptyCells}</tr>";
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($job['name']) ?> - Levi's PDF Data Extractor</title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- App Stylesheet -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>

<!-- Global Loading Overlay (shown during heavy tasks) -->
<div id="app-loading-overlay" class="app-loading-overlay d-none">
    <div class="loading-card">
        <div class="loading-spinner-ring"></div>
        <div class="loading-title" id="loading-overlay-title">กำลังโหลดข้อมูลใน Job...</div>
        <div class="loading-subtitle" id="loading-overlay-sub">กรุณารอสักครู่ ระบบกำลังดึงข้อมูลจากฐานข้อมูล</div>

        <div id="loading-overlay-progress-box" class="mt-3 d-none">
            <div class="d-flex justify-content-between align-items-center small mb-1">
                <span class="text-secondary fw-medium" id="loading-overlay-step">0 / 0 ไฟล์</span>
                <span class="fw-bold text-primary tabular-num" id="loading-overlay-pct">0%</span>
            </div>
            <div class="progress" style="height: 8px; border-radius: 9999px; background: #e2e8f0;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="loading-overlay-bar" style="width: 0%;"></div>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm mt-3 px-3 d-none" id="loading-overlay-stop-btn" style="border-radius: 8px;">
                <i class="fa-solid fa-stop me-1"></i> หยุดประมวลผล
            </button>
        </div>
    </div>
</div>

<div class="main-wrapper">

    <!-- Top Bar -->
    <div class="app-header">
        <div class="app-brand">
            <a href="job.php?job_id=<?= (int)$job['id'] ?>" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;" title="กลับหน้าเลือกงานของ Job">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <span class="header-title">
                <i class="fa-solid fa-folder text-warning me-1"></i> <?= htmlspecialchars($job['name']) ?>
            </span>
            <span class="header-file-pill folder-link" title="คลิกเพื่อเปิดโฟลเดอร์ upload here ใน Explorer" onclick="openJobFolder('upload')">
                <i class="fa-regular fa-folder-open me-1 text-primary"></i>
                upload here/ (<strong id="stat-myfile" class="tabular-num text-dark"><?= number_format($uploadCount) ?></strong> ไฟล์)
            </span>
        </div>

        <!-- Center Tabs -->
        <div class="header-tabs">
            <button class="header-tab-btn <?= $initialTab === 'pdf' ? 'active' : '' ?>" id="btn-tab-pdf" onclick="switchTab('pdf')">
                <i class="fa-solid fa-file-invoice text-danger me-1"></i> 1. ดึงข้อมูลจาก PDF
            </button>
            <button class="header-tab-btn <?= $initialTab === 'asn' ? 'active' : '' ?>" id="btn-tab-asn" onclick="switchTab('asn')">
                <i class="fa-solid fa-scale-balanced text-success me-1"></i> 2. เปรียบเทียบกับไฟล์ ASN
            </button>
            <button class="header-tab-btn <?= $initialTab === 'dashboard' ? 'active' : '' ?>" id="btn-tab-dashboard" onclick="switchTab('dashboard')">
                <i class="fa-solid fa-chart-pie text-primary me-1"></i> 3. ภาพรวม
            </button>
        </div>

        <div class="d-flex align-items-center gap-2">
            <a href="read_cn_error01.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; font-size: 0.82rem;">
                <i class="fa-solid fa-file-invoice text-danger me-1"></i> read_cn_error01
            </a>
        </div>
    </div>

    <!-- ================= VIEW 1: PDF EXTRACTION ================= -->
    <div id="view-pdf-section" data-step="<?= $uploadCount > 0 ? 'extract' : 'idle' ?>" style="display: <?= $initialTab === 'pdf' ? 'flex' : 'none' ?>;">
        <!-- Toolbar Ribbon -->
        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <button class="btn btn-sm px-3 btn-tb btn-tb-accent btn-step-extract" id="btn-start">
                    <i class="fa-solid fa-play me-1"></i> ดึงข้อมูลจาก upload here
                </button>
                <button class="btn btn-outline-danger btn-sm" id="btn-stop" style="display:none;">
                    <i class="fa-solid fa-stop me-1"></i> หยุด
                </button>

                <div class="modern-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="form-control form-control-sm" id="global-search" placeholder="ค้นหาข้อมูลในตาราง...">
                    <button type="button" class="btn-clear" id="btn-clear-search" style="display: none;"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="toolbar-end d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-tb" id="btn-copy-excel" disabled>
                        <i class="fa-solid fa-copy"></i> Copy for Excel
                    </button>
                    <button class="btn btn-sm btn-tb" id="btn-export-excel" disabled onclick="exportPdfToExcel()">
                        <i class="fa-solid fa-file-excel me-1"></i> Excel (.xlsx)
                    </button>
                    <button class="btn btn-sm btn-tb btn-tb-quiet" id="btn-clear-job" onclick="openClearDataModal('pdf')">
                        <i class="fa-solid fa-rotate-left me-1"></i> ล้างข้อมูล PDF
                    </button>
                </div>
            </div>
        </div>

        <!-- 27-Column Data Table with Resizable & Sortable Headers -->
        <div class="table-container">
            <table class="table mb-0 align-middle" id="detailed-table">
                <thead>
                    <tr>
                        <th data-col="#" data-type="number" style="width: 50px;"># <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="filename" data-type="string" style="width: 440px;">File Name <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="page_no" data-type="number" class="text-center" style="width: 75px;">Page <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="tax_invoice" data-type="string" style="width: 140px;">Tax Invoice No. <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="date" data-type="string" style="width: 110px;">Date <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="address" data-type="string" style="width: 320px;">Address <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="tax_id" data-type="string" style="width: 130px;">Tax ID <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="branch_code" data-type="string" class="text-center" style="width: 100px;">Branch Code <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="ship_to_party" data-type="string" style="width: 250px;">Ship To Party <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="term_a" data-type="string" class="text-center" style="width: 85px;">Term A <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="term_b" data-type="string" style="width: 180px;">Term B <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="product_sz" data-type="string" style="width: 150px;">Product/Size <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="barcode" data-type="string" style="width: 140px;">Barcode <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="description" data-type="string" style="width: 120px;">Description <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="units" data-type="number" class="text-center" style="width: 75px;">Quantity <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="unit_price" data-type="currency" class="text-end" style="width: 110px;">Unit Price <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="amount" data-type="currency" class="text-end" style="width: 115px;">Amount <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="total_shipped" data-type="number" class="text-center" style="width: 115px;">Total Shipped <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="no_cartons" data-type="string" class="text-center" style="width: 95px;">Cartons <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="promotion_discount" data-type="currency" class="text-end" style="width: 120px;">Promo Discount <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="total_amount" data-type="currency" class="text-end" style="width: 120px;">Total Amount <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="discount_percent" data-type="string" class="text-center" style="width: 105px;">Discount % <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="total_discount" data-type="currency" class="text-end" style="width: 115px;">Total Discount <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="amt_after_discount" data-type="currency" class="text-end" style="width: 125px;">Amt After Disc <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="amt_excl_vat" data-type="currency" class="text-end" style="width: 125px;">Amt Excl VAT <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="vat_amount" data-type="currency" class="text-end" style="width: 100px;">VAT Amount <i class="fa-solid fa-sort sort-icon"></i></th>
                        <th data-col="amt_incl_vat" data-type="currency" class="text-end" style="width: 125px;">Amt Incl VAT <i class="fa-solid fa-sort sort-icon"></i></th>
                    </tr>
                </thead>
                <tbody id="detailed-tbody">
                    <?php if (intval($job['total_items'] ?? 0) === 0): ?>
                        <?= renderPdfEmptyPlaceholderRows(18) ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================= VIEW 2: ASN RECONCILIATION ================= -->
    <div id="view-asn-section" data-step="<?= $asnStep ?>" style="display: <?= $initialTab === 'asn' ? 'flex' : 'none' ?>;">
        <!-- Toolbar Ribbon -->
        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <input type="file" id="asnFileInput" accept=".xlsx, .xls" multiple class="d-none" onchange="handleAsnFileSelect(event)">
                <button class="btn btn-sm px-3 btn-tb btn-tb-green btn-step-select" onclick="document.getElementById('asnFileInput').click()">
                    <i class="fa-solid fa-file-circle-plus me-1"></i> เลือกไฟล์ ASN เพิ่ม (.xlsx)
                </button>

                <span class="header-file-pill toolbar-file-pill" id="asn-file-pill" title="<?= !empty($job['asn_filename']) ? htmlspecialchars($job['asn_filename']) : 'ยังไม่ได้เลือกไฟล์ ASN' ?>">
                    <i class="fa-regular fa-file-excel"></i>
                    <span id="asn-filename-text"><?= !empty($job['asn_filename']) ? htmlspecialchars($job['asn_filename']) : 'ยังไม่ได้เลือกไฟล์ ASN' ?></span>
                </span>

                <div id="asn-sheet-wrapper" class="d-none align-items-center gap-1">
                    <span class="text-muted small fw-medium">ชีต:</span>
                    <select id="asn-sheet-select" class="form-select form-select-sm toolbar-sheet-select" onchange="handleAsnSheetChange(this.value)"></select>
                </div>

                <input type="file" id="vbrpFileInput" accept=".xlsx" class="d-none" onchange="handleVbrpFileSelect(event)">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm px-3 btn-tb btn-tb-green btn-step-vbrp" id="btn-vbrp-select" onclick="document.getElementById('vbrpFileInput').click()">
                        <i class="fa-solid fa-file-circle-plus me-1"></i> เลือกไฟล์ VBRP (.xlsx)
                    </button>
                    <span class="header-file-pill toolbar-file-pill toolbar-vbrp-pill" id="vbrp-pill" title="<?= htmlspecialchars(VBRP_PILL_TITLE) ?>">
                        <i class="fa-regular fa-file-excel"></i>
                        <span id="vbrp-pill-text" class="tabular-num"><?= $statusCounts['vbrp_rows'] > 0 ? 'VBRP: ' . number_format($statusCounts['vbrp_invoices']) . ' บิล / ' . number_format($statusCounts['vbrp_rows']) . ' แถว' : 'ยังไม่ได้นำเข้า VBRP' ?></span>
                    </span>
                </div>

                <div class="toolbar-end d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-tb btn-tb-quiet" id="btn-clear-vbrp"<?= $statusCounts['vbrp_rows'] > 0 ? '' : ' disabled' ?> onclick="openVbrpClearModal()">
                        <i class="fa-solid fa-rotate-left me-1"></i> ล้างข้อมูล VBRP
                    </button>
                    <button class="btn btn-sm btn-tb btn-tb-quiet" id="btn-clear-asn" onclick="openClearDataModal('asn')">
                        <i class="fa-solid fa-rotate-left me-1"></i> ล้างข้อมูล ASN
                    </button>
                </div>
            </div>

            <div class="toolbar-row">
                <div class="recon-filter-tabs" id="reconFilterGroup">
                    <button type="button" class="recon-filter-btn active" data-filter="ALL" onclick="setReconFilter('ALL', this)">
                        ทั้งหมด <span id="count-all" class="recon-count tabular-num">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="MATCH" onclick="setReconFilter('MATCH', this)" title="PDF ถูกต้องตั้งแต่แรกและ ASN ไม่ผิด พร้อมสำหรับพิมพ์ VBRP ที่ไม่ตรงแสดงในหมายเหตุ ไม่ทำให้บิลผิด">
                        <i class="fa-solid fa-circle-check"></i>สมบูรณ์ <span id="count-match" class="recon-count tabular-num">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="FIXED" onclick="setReconFilter('FIXED', this)" title="รายการที่ถูกต้องหลังกดแก้ไข พร้อมสำหรับพิมพ์">
                        <i class="fa-solid fa-wrench"></i>แก้ไขแล้ว <span id="count-fixed" class="recon-count tabular-num">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="PENDING_FIX" onclick="setReconFilter('PENDING_FIX', this)" title="หน้าของบิลต้องปรับ (เลข Tax Invoice คลาดเคลื่อน หรือยอดรวมท้ายบิลซ้ำในหน้าต่อเนื่อง) หรือไฟล์ที่รวมหลายบิลต้องแยก ทำแล้วข้อมูลตรงกับ ASN">
                        <i class="fa-solid fa-wrench"></i>รอแก้ไข PDF <span id="count-pending-fix" class="recon-count tabular-num">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="PENDING_ASN" onclick="setReconFilter('PENDING_ASN', this)" title="<?= htmlspecialchars(PENDING_ASN_HINT) ?>">
                        <i class="fa-solid fa-file-circle-exclamation"></i>รอแก้ไข ASN <span id="count-pending-asn" class="recon-count tabular-num">0</span>
                    </button>
                    <button type="button" class="recon-filter-btn" data-filter="MISMATCH" onclick="setReconFilter('MISMATCH', this)" title="PDF ไม่ตรงกับแหล่งอื่น (ASN, VBRP) แม้ปรับหน้าของบิลแล้ว เช่น จำนวนสินค้าไม่ตรงกัน ยอดรวมไม่ตรง (ต่างกันเกิน <?= AsnReconciler::AMOUNT_TOLERANCE ?> บาท) หรือไม่พบใน PDF">
                        <i class="fa-solid fa-triangle-exclamation"></i>ผิดพลาด <span id="count-mismatch" class="recon-count tabular-num">0</span>
                    </button>
                </div>

                <div class="modern-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="form-control form-control-sm" id="asn-search" placeholder="ค้นหาข้อมูลในตาราง...">
                    <button type="button" class="btn-clear" id="btn-clear-asn-search" style="display: none;"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="toolbar-end d-flex align-items-center gap-2">
                    <button class="btn btn-sm px-3 btn-tb btn-tb-fix" id="btn-fix-all-pending" style="display: <?= $pendingFixCount > 0 ? 'inline-flex; align-items: center' : 'none' ?>;" onclick="openFixAllConfirmModal()">
                        <i class="fa-solid fa-wrench me-1"></i> แก้ไข (<span id="btn-fix-all-count"><?= number_format($pendingFixCount) ?></span>)
                    </button>
                    <button class="btn btn-sm px-3 btn-tb btn-tb-accent btn-step-compare" id="btn-check-pdf"<?= $statusCounts['asn_rows'] > 0 && $statusCounts['vbrp_rows'] > 0 ? '' : ' disabled' ?> onclick="openReconcileConfirmModal()">
                        <i class="fa-solid fa-scale-balanced me-1"></i> เทียบกับ PDF
                    </button>
                    <button class="btn btn-sm btn-tb" id="btn-export-recon"<?= $asnStep === 'select' ? ' disabled' : '' ?> onclick="exportReconcileToExcel()">
                        <i class="fa-solid fa-file-excel me-1"></i> Excel (.xlsx)
                    </button>
                    <button class="btn btn-sm px-3 btn-tb btn-tb-slate btn-step-print" id="btn-print"<?= $asnStep === 'print' ? '' : ' disabled' ?> onclick="openPrintConfirmModal()">
                        <i class="fa-solid fa-print me-1"></i> สั่งพิมพ์
                    </button>
                </div>
            </div>
        </div>

        <!-- ASN Comparison Table -->
        <div class="table-container">
            <table class="table mb-0 align-middle" id="asn-table">
                <thead>
                    <tr>
                        <?= renderAsnHeaderCells() ?>
                    </tr>
                </thead>
                <tbody id="asn-tbody">
                    <?php if (empty($job['asn_filename'])): ?>
                        <?= renderReconEmptyPlaceholderRows(18) ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================= VIEW 3: DASHBOARD ================= -->
    <div id="view-dashboard-section" style="display: <?= $initialTab === 'dashboard' ? 'flex' : 'none' ?>;">
        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <span class="dash-state is-muted" id="dashboard-state">
                    <i class="fa-solid fa-circle-notch fa-spin"></i> กำลังโหลด
                </span>
                <span class="dash-updated" id="dashboard-updated"></span>
                <button type="button" class="btn btn-sm px-3 btn-tb toolbar-end" id="btn-dashboard-refresh" onclick="loadDashboard()">
                    <i class="fa-solid fa-rotate me-1"></i> รีเฟรช
                </button>
            </div>
        </div>

        <div class="dashboard-scroll">
            <div class="dashboard-wrap">
                <div id="dashboard-notice" class="dashboard-notice d-none">
                    <i class="fa-solid fa-circle-info" id="dashboard-notice-icon"></i>
                    <span id="dashboard-notice-text"></span>
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent ms-auto" id="dashboard-notice-action" onclick="switchTab('asn')">
                        <i class="fa-solid fa-scale-balanced me-1"></i> ไปหน้าเปรียบเทียบ
                    </button>
                </div>

                <section class="dash-hero">
                    <div class="dash-hero-top">
                        <span class="dash-icon" style="--tone-bg: #f1f5f9; --tone-fg: #475569;"><i class="fa-solid fa-hashtag"></i></span>
                        <div class="dash-hero-title">
                            <div class="dash-label">เลขบิลทั้งหมด</div>
                            <div class="dash-hint">เลข Tax Invoice ในไฟล์ ASN นับเป็นเลขบิล ไม่ใช่จำนวนแถว บิลหนึ่งเลขใช้สถานะของรายการที่แย่ที่สุดในบิลนั้น</div>
                        </div>
                        <div class="dash-number dash-number-hero tabular-num" id="dash-total">–</div>
                    </div>
                    <div class="dash-bar is-empty" id="dash-bar" role="img" aria-label="สัดส่วนสถานะของเลขบิล">
                        <span class="dash-seg" data-seg="complete" style="--seg: #22c55e;"></span>
                        <span class="dash-seg" data-seg="fixed" style="--seg: #3b82f6;"></span>
                        <span class="dash-seg" data-seg="not_fixed" style="--seg: #f59e0b;"></span>
                        <span class="dash-seg" data-seg="pending_asn" style="--seg: #a855f7;"></span>
                        <span class="dash-seg" data-seg="error" style="--seg: #ef4444;"></span>
                    </div>
                    <ul class="dash-legend">
                        <li title="ถูกต้องตั้งแต่แรก ไม่ต้องแก้ไข"><i class="dash-dot" style="--seg: #22c55e;"></i>สมบูรณ์ <b class="tabular-num" data-legend="complete">–</b></li>
                        <li title="แก้ไขแล้วและถูกต้อง ไม่นับบิลที่แก้ไขแล้วแต่ยังผิดพลาด"><i class="dash-dot" style="--seg: #3b82f6;"></i>แก้ไขแล้ว <b class="tabular-num" data-legend="fixed">–</b></li>
                        <li title="หน้าของบิลยังรอกดแก้ไข"><i class="dash-dot" style="--seg: #f59e0b;"></i>รอแก้ไข PDF <b class="tabular-num" data-legend="not_fixed">–</b></li>
                        <li title="<?= htmlspecialchars(PENDING_ASN_HINT) ?>"><i class="dash-dot" style="--seg: #a855f7;"></i>รอแก้ไข ASN <b class="tabular-num" data-legend="pending_asn">–</b></li>
                        <li><i class="dash-dot" style="--seg: #ef4444;"></i>ผิดพลาด <b class="tabular-num" data-legend="error">–</b></li>
                    </ul>
                </section>

                <div class="dash-grid">
                    <article class="dash-panel" style="--tone-bg: #dcfce7; --tone-bd: #bbf7d0; --tone-fg: #15803d;">
                        <header class="dash-panel-head">
                            <span class="dash-icon"><i class="fa-solid fa-circle-check"></i></span>
                            <span class="dash-label" title="ถูกต้องตั้งแต่แรก ไม่ต้องแก้ไข">สมบูรณ์</span>
                            <span class="dash-pct tabular-num" data-pct="complete">–</span>
                        </header>
                        <div class="dash-number tabular-num" id="dash-complete">–</div>
                        <div class="dash-hint">ถูกต้องตั้งแต่แรก ไม่ผ่านการแก้ไข</div>
                    </article>

                    <article class="dash-panel" style="--tone-bg: #fef3c7; --tone-bd: #fde68a; --tone-fg: #b45309;">
                        <header class="dash-panel-head">
                            <span class="dash-icon"><i class="fa-solid fa-wrench"></i></span>
                            <span class="dash-label">รอแก้ไข PDF ทั้งหมด</span>
                            <span class="dash-pct tabular-num" data-pct="needs_fix">–</span>
                        </header>
                        <div class="dash-number tabular-num" id="dash-needs_fix">–</div>
                        <div class="dash-hint">หน้าของบิลต้องปรับ หรือไฟล์ที่รวมหลายบิลต้องแยก ไม่ลดลงหลังกดแก้ไข เพราะรวมบิลที่แก้ไขแล้วด้วย</div>
                        <div class="dash-bar dash-bar-mini is-empty" id="dash-mini-bar" role="img" aria-label="สัดส่วนยังแก้ไขไม่ได้กับแก้ไขแล้ว">
                            <span class="dash-seg" data-seg="not_fixed" style="--seg: #f59e0b;"></span>
                            <span class="dash-seg" data-seg="fixed" style="--seg: #3b82f6;"></span>
                        </div>
                        <dl class="dash-split">
                            <div>
                                <dt><i class="dash-dot" style="--seg: #f59e0b;"></i>ยังแก้ไขไม่ได้</dt>
                                <dd class="tabular-num" id="dash-not_fixed">–</dd>
                            </div>
                            <div>
                                <dt><i class="dash-dot" style="--seg: #3b82f6;"></i>แก้ไขแล้ว</dt>
                                <dd class="tabular-num" id="dash-fixed">–</dd>
                            </div>
                        </dl>
                    </article>

                    <article class="dash-panel" style="--tone-bg: #f3e8ff; --tone-bd: #e9d5ff; --tone-fg: #7e22ce;">
                        <header class="dash-panel-head">
                            <span class="dash-icon"><i class="fa-solid fa-file-circle-exclamation"></i></span>
                            <span class="dash-label">รอแก้ไข ASN</span>
                            <span class="dash-pct tabular-num" data-pct="pending_asn">–</span>
                        </header>
                        <div class="dash-number tabular-num" id="dash-pending_asn">–</div>
                        <div class="dash-hint"><?= htmlspecialchars(PENDING_ASN_HINT) ?></div>
                    </article>

                    <article class="dash-panel" style="--tone-bg: #fee2e2; --tone-bd: #fecaca; --tone-fg: #b91c1c;">
                        <header class="dash-panel-head">
                            <span class="dash-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                            <span class="dash-label">ผิดพลาด</span>
                            <span class="dash-pct tabular-num" data-pct="error">–</span>
                        </header>
                        <div class="dash-number tabular-num" id="dash-error">–</div>
                        <div class="dash-hint">ข้อมูลใน PDF ไม่ตรงกับแหล่งอื่น (ASN, VBRP)</div>
                        <div class="dash-panel-note d-none" id="dash-error-note"></div>
                    </article>
                </div>

                <div class="dash-verdict-strip d-none" id="dash-verdict-strip" title="นับเลขบิลที่แหล่งนั้นผิดอย่างน้อยหนึ่งรายการ เฉพาะบิลที่มีใน VBRP"></div>

                <section class="dash-table-panel">
                    <header class="dash-table-head">
                        <span class="dash-icon" style="--tone-bg: #f1f5f9; --tone-fg: #475569;"><i class="fa-solid fa-table-list"></i></span>
                        <div class="dash-hero-title">
                            <div class="dash-label">สรุปรายเลขบิล</div>
                            <div class="dash-hint">1 เลขบิล 1 แถว จำนวนชิ้นและจำนวนหน้ามาจากบิล PDF ส่วนยอดก่อน VAT มาจากไฟล์ ASN</div>
                        </div>
                        <span class="dash-pct tabular-num" id="dash-table-count" style="--tone-bg: #f1f5f9; --tone-bd: #e2e8f0; --tone-fg: #475569;">–</span>
                        <div class="toolbar-row dash-table-tools">
                            <div class="recon-filter-tabs" id="dashFilterGroup">
                                <button type="button" class="recon-filter-btn active" data-filter="ALL" onclick="setDashboardFilter('ALL')">
                                    ทั้งหมด <span class="recon-count tabular-num" data-dash-count="ALL">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="MATCH" onclick="setDashboardFilter('MATCH')" title="ถูกต้องตั้งแต่แรก ไม่ผ่านการแก้ไข">
                                    <i class="fa-solid fa-circle-check"></i>สมบูรณ์ <span class="recon-count tabular-num" data-dash-count="MATCH">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="FIXED" onclick="setDashboardFilter('FIXED')" title="ถูกต้องหลังกดแก้ไข">
                                    <i class="fa-solid fa-wrench"></i>แก้ไขแล้ว <span class="recon-count tabular-num" data-dash-count="FIXED">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="PENDING_FIX" onclick="setDashboardFilter('PENDING_FIX')" title="หน้าของบิลต้องปรับ หรือไฟล์ที่รวมหลายบิลต้องแยก ทำแล้วข้อมูลตรงกับ ASN">
                                    <i class="fa-solid fa-wrench"></i>รอแก้ไข PDF <span class="recon-count tabular-num" data-dash-count="PENDING_FIX">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="PENDING_ASN" onclick="setDashboardFilter('PENDING_ASN')" title="<?= htmlspecialchars(PENDING_ASN_HINT) ?>">
                                    <i class="fa-solid fa-file-circle-exclamation"></i>รอแก้ไข ASN <span class="recon-count tabular-num" data-dash-count="PENDING_ASN">0</span>
                                </button>
                                <button type="button" class="recon-filter-btn" data-filter="MISMATCH" onclick="setDashboardFilter('MISMATCH')" title="ข้อมูลใน PDF ไม่ตรงกับแหล่งอื่น (ASN, VBRP) รวมบิลที่แก้ไขแล้วแต่ยังผิดพลาด">
                                    <i class="fa-solid fa-triangle-exclamation"></i>ผิดพลาด <span class="recon-count tabular-num" data-dash-count="MISMATCH">0</span>
                                </button>
                            </div>

                            <div class="modern-search">
                                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                                <input type="text" class="form-control form-control-sm" id="dash-search" placeholder="ค้นหาข้อมูลในตาราง..." autocomplete="off">
                                <button type="button" class="btn-clear" id="btn-clear-dash-search" style="display: none;"><i class="fa-solid fa-xmark"></i></button>
                            </div>

                            <div class="btn-group toolbar-end">
                                <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-green dropdown-toggle" id="btn-export-dash" disabled data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fa-solid fa-file-excel me-1"></i> Export Report (.xlsx)
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" id="dashExportMenu" style="min-width: 260px; font-size: 0.82rem;">
                                    <li><h6 class="dropdown-header py-1">เลือก Report ที่ต้องการดาวน์โหลด (แยกไฟล์)</h6></li>
                                    <li><a class="dropdown-item py-1 d-flex justify-content-between align-items-center" href="#" onclick="exportDashboardReport('MATCH'); return false;"><span><i class="fa-solid fa-circle-check text-success me-2"></i>1. สมบูรณ์</span><span class="badge bg-light text-dark border tabular-num" data-export-count="MATCH">0</span></a></li>
                                    <li><a class="dropdown-item py-1 d-flex justify-content-between align-items-center" href="#" onclick="exportDashboardReport('FIXED'); return false;"><span><i class="fa-solid fa-wrench text-primary me-2"></i>2. แก้ไขแล้ว</span><span class="badge bg-light text-dark border tabular-num" data-export-count="FIXED">0</span></a></li>
                                    <li><a class="dropdown-item py-1 d-flex justify-content-between align-items-center" href="#" onclick="exportDashboardReport('PENDING_FIX'); return false;"><span><i class="fa-solid fa-wrench text-warning me-2"></i>3. รอแก้ไข PDF</span><span class="badge bg-light text-dark border tabular-num" data-export-count="PENDING_FIX">0</span></a></li>
                                    <li><a class="dropdown-item py-1 d-flex justify-content-between align-items-center" href="#" onclick="exportDashboardReport('PENDING_ASN'); return false;"><span><i class="fa-solid fa-file-circle-exclamation me-2" style="color:#9333ea;"></i>4. รอแก้ไข ASN</span><span class="badge bg-light text-dark border tabular-num" data-export-count="PENDING_ASN">0</span></a></li>
                                    <li><a class="dropdown-item py-1 d-flex justify-content-between align-items-center" href="#" onclick="exportDashboardReport('MISMATCH'); return false;"><span><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>5. ผิดพลาด (แยกชีตสาเหตุ)</span><span class="badge bg-light text-dark border tabular-num" data-export-count="MISMATCH">0</span></a></li>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <li><a class="dropdown-item py-1 fw-semibold text-success" href="#" onclick="exportDashboardReport('ALL_SEPARATE'); return false;"><i class="fa-solid fa-layer-group me-2"></i>ดาวน์โหลดแยกครบทุกไฟล์ที่มีข้อมูล</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="dash-error-subfilters d-none" id="dashErrorSubfilters"></div>
                    </header>
                    <div class="dash-table-scroll">
                        <table class="table mb-0 align-middle" id="dash-table">
                            <thead>
                                <tr>
                                    <th data-col="#" data-type="number" class="text-center" style="width: 44px;"># <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="store" data-type="string" class="sorted" style="width: 96px;" title="เลขที่สาขา: Dest Store No. ในไฟล์ ASN (ถ้า ASN ไม่มี ใช้เลขสาขาใน Ship to Party ของ PDF) ตารางเรียงตามสาขา แล้วตามเลขที่บิล">Dest Store No. <i class="fa-solid fa-sort-up sort-icon"></i></th>
                                    <th data-col="branch_code" data-type="string" style="width: 88px;" title="รหัสสาขาทางภาษี (Branch Code จาก PDF เช่น 00000, 00046)">Branch Code <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="invoice_no" data-type="string" style="width: 98px;" title="เลขที่บิล">Tax Invoice No. <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="source_file" data-type="string" style="width: 150px;" title="ไฟล์ PDF ต้นฉบับที่มีหน้าของบิลนี้">Source File <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="units" data-type="number" class="text-center" style="width: 78px;" title="จำนวนไอเทม (ชิ้น) จาก PDF: จำนวนชิ้นรวมของบิล เท่ากับ Total Shipped ท้ายบิล">Total Qty (PDF) <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="total_cost_ex_vat" data-type="number" class="text-end" style="width: 128px;" title="ยอดรวมก่อน VAT จาก ASN (Total Cost Ex VAT)">Total Cost Ex VAT (ASN) <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="pages" data-type="number" class="text-center" style="width: 72px;" title="จำนวนหน้า">Pages <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="status" data-type="number" class="text-center" style="width: 8rem;" title="ผลการดำเนินการ">Status <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="was" data-type="string" style="width: 100px;" title="สถานะก่อนแก้ไข: สถานะที่บิลเคยเป็นก่อนสถานะตอนนี้ ตามลำดับที่เกิด ชี้ที่ช่องเพื่อดูวันที่และสิ่งที่ทำ (บันทึกตั้งแต่การเทียบ การนำเข้า หรือการแก้ไขครั้งแรกหลังมีหน้านี้)">Previous status <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="verdict_pdf" data-type="number" class="text-center dash-verdict-head" style="width: 60px;" title="<?= htmlspecialchars(VERDICT_RULE) ?> (ผลที่แย่ที่สุดของรายการในบิล)">PDF <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="verdict_asn" data-type="number" class="text-center dash-verdict-head" style="width: 60px;" title="<?= htmlspecialchars(VERDICT_RULE) ?> (ผลที่แย่ที่สุดของรายการในบิล)">ASN <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="verdict_vbrp" data-type="number" class="text-center dash-verdict-head" style="width: 60px;" title="<?= htmlspecialchars(VERDICT_RULE) ?> (ผลที่แย่ที่สุดของรายการในบิล)">VBRP <i class="fa-solid fa-sort sort-icon"></i></th>
                                    <th data-col="note" data-type="string" title="หมายเหตุ บรรทัดสีเทาบอกแหล่งอื่นที่ไม่ตรง (ASN หรือ VBRP) และสิ่งที่ PDF ยังต้องแก้หลังแก้ ASN">Remark <i class="fa-solid fa-sort sort-icon"></i></th>
                                </tr>
                            </thead>
                            <tbody id="dash-tbody"></tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div class="app-statusbar">
        <div class="d-flex align-items-center gap-3" id="job-status-counts">
            <div>
                <i class="fa-regular fa-file-lines text-primary me-1"></i>
                PDF: <strong id="footer-files-count" class="tabular-num text-dark"><?= number_format($statusCounts['pdf_files']) ?></strong> ไฟล์
            </div>
            <span class="text-muted">|</span>
            <div>
                <i class="fa-solid fa-list-check text-info me-1"></i>
                รายการ: <strong id="footer-items-count" class="tabular-num text-dark"><?= number_format($statusCounts['pdf_rows']) ?></strong> รายการ
            </div>
            <span class="text-muted">|</span>
            <div>
                <i class="fa-solid fa-file-excel text-success me-1"></i>
                ASN: <strong id="footer-asn-files-count" class="tabular-num text-dark"><?= number_format($statusCounts['asn_files']) ?></strong> ไฟล์
            </div>
            <span class="text-muted">|</span>
            <div id="footer-asn-rows-item" data-rows="<?= (int)$statusCounts['asn_rows'] ?>"<?= $statusCounts['asn_rows'] > 0 ? ' title="' . number_format($statusCounts['asn_rows']) . ' แถวที่นำเข้า บาร์โค้ดซ้ำในบิลเดียวกันถูกรวมเป็นรายการเดียวในตารางหลังเทียบกับ PDF"' : '' ?>>
                <i class="fa-solid fa-list-check text-success me-1"></i>
                รายการ: <strong id="footer-asn-rows-count" class="tabular-num text-dark"><?= number_format($statusCounts['asn_rows']) ?></strong> รายการ
            </div>
            <span class="text-muted">|</span>
            <div id="footer-vbrp-rows-item" data-rows="<?= (int)$statusCounts['vbrp_rows'] ?>" data-invoices="<?= (int)$statusCounts['vbrp_invoices'] ?>"<?= $statusCounts['vbrp_rows'] > 0 ? ' title="' . number_format($statusCounts['vbrp_invoices']) . ' บิล"' : '' ?>>
                <i class="fa-solid fa-table-list text-success me-1"></i>
                VBRP: <strong id="footer-vbrp-rows-count" class="tabular-num text-dark"><?= number_format($statusCounts['vbrp_rows']) ?></strong> แถว
            </div>
        </div>
        <a href="#" class="folder-link small" title="คลิกเพื่อเปิดโฟลเดอร์ TAX INVOICE ของ Job นี้ใน Explorer" onclick="openJobFolder('ti'); return false;">
            <i class="fa-regular fa-folder-closed me-1 text-primary"></i>
            โฟลเดอร์: <span class="tabular-num"><?= htmlspecialchars(JobFolders::displayPath($jobManager->getJobTaxInvoiceDir($job, false))) ?>/</span>
        </a>
    </div>

</div>

<!-- Modal: ตรวจพบไฟล์เดิมใน Job (ข้าม หรือ ดึงทับ) -->
<div class="modal fade" id="duplicateCheckModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom pb-3 bg-light">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-copy text-primary me-2"></i>ตรวจสอบไฟล์ใน upload here
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <div class="alert alert-info d-flex align-items-center gap-3 mb-3">
                    <i class="fa-solid fa-folder-open text-primary fs-3"></i>
                    <div>
                        <div>ตรวจพบไฟล์ PDF ใน upload here ทั้งหมด: <strong class="tabular-num" id="scanTotalFiles">0</strong> ไฟล์</div>
                        <div class="text-muted small">ระบบตรวจพบว่าใน Job นี้มีไฟล์ที่เคยดึงแล้วบางส่วน</div>
                    </div>
                </div>

                <div class="list-group mb-3">
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fa-solid fa-file-circle-plus text-success me-2"></i>ไฟล์ใหม่ (ยังไม่เคยดึงเข้า Job นี้)
                        </div>
                        <span class="badge bg-success rounded-pill tabular-num fs-6" id="scanNewCount">0</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fa-solid fa-file-circle-check text-warning me-2"></i>ไฟล์เดิม (เคยดึงเข้า Job นี้แล้ว)
                        </div>
                        <span class="badge bg-warning text-dark rounded-pill tabular-num fs-6" id="scanExistingCount">0</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center" id="scanDuplicateRow" style="display: none;">
                        <div>
                            <i class="fa-solid fa-copy text-danger me-2"></i>ไฟล์ใหม่ที่เลขบิลซ้ำกับไฟล์อื่นใน Job นี้
                            <div class="small text-muted mt-1" id="scanDuplicateDetail"></div>
                        </div>
                        <span class="badge bg-danger rounded-pill tabular-num fs-6" id="scanDuplicateCount">0</span>
                    </div>
                </div>

                <p class="text-muted small mb-0">
                    <i class="fa-solid fa-circle-question me-1"></i> กรุณาเลือกรูปแบบที่ต้องการดำเนินการ:
                </p>
            </div>
            <div class="modal-footer border-top pt-3 bg-light d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">ยกเลิก</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm px-3 fw-semibold" id="btnSkipExisting" onclick="startBatchExtraction('skip')">
                        <i class="fa-solid fa-forward me-1"></i> ข้ามไฟล์ที่ซ้ำ
                    </button>
                    <button type="button" class="btn btn-danger btn-sm px-3 fw-semibold" id="btnOverwriteAll" onclick="startBatchExtraction('overwrite')">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> เขียนทับทั้งหมด
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmPrintModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-print text-primary me-2"></i>ยืนยันการสั่งพิมพ์
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope" style="background: #eff6ff; border-color: #bfdbfe;">
                    <div class="fix-scope-icon" style="background: #dbeafe; color: #1d4ed8;"><i class="fa-solid fa-folder-tree"></i></div>
                    <div>
                        <div class="fix-scope-title" id="printModalTitle">สร้าง print ครั้งที่ 1</div>
                        <div class="fix-scope-detail" id="printModalDetail"></div>
                    </div>
                </div>

                <div class="fix-steps-label">สิ่งที่ระบบจะทำ</div>
                <ol class="fix-steps">
                    <li>คัดลอก PDF ของบิลที่สมบูรณ์ (รวมบิลที่แก้ไขแล้ว) ไปไว้ในโฟลเดอร์ <span class="tabular-num" id="printModalFolder"></span></li>
                    <li>แยกโฟลเดอร์ตามสาขา (Dest Store No. ใน ASN)</li>
                    <li>ตั้งชื่อไฟล์เป็น <span class="tabular-num">ลำดับ_เลข Tax Invoice.pdf</span> เรียงตามเลข Tax Invoice</li>
                </ol>

                <div class="small text-secondary mb-2" id="printModalSkipped"></div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>เป็นการคัดลอก ไฟล์ใน finished ไม่ถูกแตะ ถ้าต้องการสั่งพิมพ์รอบนั้นใหม่ ให้ลบโฟลเดอร์รอบนั้นทิ้ง</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-go" id="btnConfirmPrint" onclick="executePrint()">
                    <i class="fa-solid fa-print me-1"></i> ยืนยันสั่งพิมพ์
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: บิลซ้ำในไฟล์ ASN -->
<div class="modal fade" id="asnDuplicateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-copy text-danger me-2"></i>พบเลขบิลซ้ำกับไฟล์ ASN อื่น
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope" style="background: #fef2f2; border-color: #fecaca;">
                    <div class="fix-scope-icon" style="background: #fee2e2; color: #b91c1c;"><i class="fa-solid fa-file-circle-exclamation"></i></div>
                    <div>
                        <div class="fix-scope-title"><span id="asnDupCount" class="tabular-num">0</span> บิลมีอยู่แล้ว</div>
                        <div class="fix-scope-detail" id="asnDupFiles"></div>
                    </div>
                </div>
                <div class="small text-secondary mb-2">ตัวอย่างเลขบิล: <span class="tabular-num" id="asnDupSample"></span></div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>ถ้านำเข้าทั้งสองชุด จำนวนสินค้าของบิลเหล่านี้จะถูกนับซ้ำและขึ้นเป็นผิดพลาด จึงมี 2 ทางเลือก ข้ามบิลที่ซ้ำ (ใช้ข้อมูลเดิม) หรือแทนที่ด้วยข้อมูลจากไฟล์ใหม่</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-between gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent" id="btnAsnDupSkip">
                        <i class="fa-solid fa-forward me-1"></i> ข้ามบิลที่ซ้ำ
                    </button>
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-quiet" id="btnAsnDupReplace">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> แทนที่ด้วยไฟล์ใหม่
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ไฟล์ ASN นี้เคยนำเข้าแล้ว -->
<div class="modal fade" id="asnSameFileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>ไฟล์ ASN นี้เคยนำเข้าแล้ว
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope" style="background: #fffbeb; border-color: #fde68a;">
                    <div class="fix-scope-icon" style="background: #fef3c7; color: #b45309;"><i class="fa-regular fa-file-excel"></i></div>
                    <div>
                        <div class="fix-scope-title" id="asnSameFileLabel"></div>
                        <div class="fix-scope-detail">
                            ในระบบ <span id="asnSameStored" class="tabular-num">0</span> แถว · ไฟล์ที่เลือก <span id="asnSameIncoming" class="tabular-num">0</span> แถว
                        </div>
                        <div class="fix-scope-detail fw-semibold" id="asnSameVerdict"></div>
                    </div>
                </div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span id="asnSameNote" style="white-space: pre-line;"></span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-between gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-accent" id="btnAsnSameSkip">
                        <i class="fa-solid fa-forward me-1"></i> ข้าม (ใช้ข้อมูลเดิม)
                    </button>
                    <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-quiet" id="btnAsnSameReplace">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> เขียนทับ
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: แจ้งเตือนข้อความ (Notice Modal) -->
<div class="modal fade" id="noticeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom pb-3 bg-light">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-warning" id="noticeModalIcon"></i>
                    <span id="noticeModalTitleText">แจ้งเตือน</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <div class="d-flex align-items-start gap-3">
                    <div class="p-2 rounded-3 bg-light border text-center" style="width: 48px; height: 48px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-folder-open text-warning fs-3" id="noticeModalBodyIcon"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark fs-6 mb-1" id="noticeModalMessage">ไม่พบไฟล์ PDF ในโฟลเดอร์ upload here</div>
                        <div class="text-secondary small" id="noticeModalDetail" style="white-space: pre-wrap; word-break: break-all;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb d-none" id="noticeModalAction"></button>
                <button type="button" class="btn btn-primary btn-sm px-4 fw-semibold" data-bs-dismiss="modal" style="border-radius: 8px;">
                    ตกลง
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ยืนยันการดึงข้อมูลจากไฟล์ ASN ลงตาราง -->
<div class="modal fade" id="confirmImportAsnModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-file-excel text-success me-2"></i>ยืนยันการดึงข้อมูลไฟล์ ASN
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="cancelPendingAsnImport()"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div style="width: 44px; height: 44px; border-radius: 10px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;">
                        <i class="fa-solid fa-cloud-arrow-down"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark fs-6" id="confirmAsnFileCount">ตรวจพบไฟล์ ASN 1 ไฟล์</div>
                        <div class="text-secondary small" id="confirmAsnSubtext">พร้อมดึงข้อมูลรายการสินค้าลงในตาราง</div>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-3 border mb-0" id="confirmAsnDetails">
                    <!-- Populated dynamically -->
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal" onclick="cancelPendingAsnImport()" style="border-radius: 8px;">
                    ยกเลิก
                </button>
                <button type="button" class="btn btn-success btn-sm px-3" id="btnConfirmImportAsn" onclick="executeConfirmedAsnImport()" style="border-radius: 8px; font-weight: 500;">
                    <i class="fa-solid fa-check me-1"></i> ยืนยันดึงข้อมูลลงตาราง
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ยืนยันการเปรียบเทียบข้อมูลกับ PDF -->
<div class="modal fade" id="confirmReconcileModal" tabindex="-1" aria-hidden="true">
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
                    <div style="width: 44px; height: 44px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;">
                        <i class="fa-solid fa-code-compare"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark fs-6">เทียบสามแหล่ง PDF, ASN และ VBRP</div>
                        <div class="text-secondary small">ระบบจะจับคู่ Tax Invoice No. และ Barcode แล้วเทียบ Qty, ยอดก่อน VAT และ VAT (<?= htmlspecialchars(AMOUNT_RULE) ?>) บิลที่มีใน VBRP ซึ่ง ASN ไม่ตรงจะรอแก้ไข ASN (แก้ไขและสั่งพิมพ์ไม่ได้จนกว่าจะนำเข้า ASN ที่ถูกต้อง) VBRP ที่ไม่ตรงแสดงในหมายเหตุ</div>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-3 border mb-3">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-secondary small"><i class="fa-solid fa-file-invoice text-danger me-1"></i> ข้อมูลจาก PDF:</span>
                        <strong class="tabular-num text-dark" id="reconModalPdfCount">0 รายการ</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 pt-2 border-bottom">
                        <span class="text-secondary small"><i class="fa-solid fa-file-excel text-success me-1"></i> ข้อมูลจาก ASN:</span>
                        <strong class="tabular-num text-dark" id="reconModalAsnCount">0 รายการ</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 pt-2">
                        <span class="text-secondary small"><i class="fa-solid fa-table-list text-success me-1"></i> ข้อมูลจาก VBRP:</span>
                        <strong class="tabular-num text-dark" id="reconModalVbrpCount">0 แถว</strong>
                    </div>
                </div>

                <div class="text-muted small">
                    คุณต้องการเริ่มดำเนินการตรวจสอบและเปรียบเทียบข้อมูลทันทีหรือไม่?
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal" style="border-radius: 8px;">
                    ยกเลิก
                </button>
                <button type="button" class="btn btn-success btn-sm px-3" id="btnConfirmReconcile" onclick="executeConfirmedReconcile()" style="border-radius: 8px; font-weight: 500;">
                    <i class="fa-solid fa-play me-1"></i> ยืนยันเริ่มเปรียบเทียบ
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: เลือกรายการล้างข้อมูลใน Job (PDF / ASN) แบบตารางพร้อม Pagination -->
<div class="modal fade" id="clearDataModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h6 class="modal-title fw-bold text-dark mb-0" id="clear-modal-title">
                        <i class="fa-solid fa-trash-can text-danger me-2"></i>เลือกรายการที่ต้องการล้างข้อมูล
                    </h6>
                    <div class="header-tabs ms-2" style="padding: 2px;">
                        <button type="button" class="header-tab-btn active" id="clear-tab-pdf" onclick="switchClearModalTarget('pdf')" style="padding: 3px 10px; font-size: 0.78rem;">
                            <i class="fa-solid fa-file-invoice text-danger me-1"></i>ข้อมูล PDF
                        </button>
                        <button type="button" class="header-tab-btn" id="clear-tab-asn" onclick="switchClearModalTarget('asn')" style="padding: 3px 10px; font-size: 0.78rem;">
                            <i class="fa-solid fa-file-excel text-success me-1"></i>ข้อมูล ASN
                        </button>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <!-- Modal Toolbar: File Filter, Search, Page Size, Cross-Page Selection Info -->
            <div class="px-3 py-2 border-bottom bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="file-combo" id="clear-modal-file-filter"></div>

                    <div class="modern-search" style="min-width: 230px;">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" class="form-control form-control-sm" id="clear-modal-search" placeholder="ค้นหาเลขบิล, บาร์โค้ด, ชื่อไฟล์...">
                        <button type="button" class="btn-clear" id="btn-clear-modal-search" style="display: none;"><i class="fa-solid fa-xmark"></i></button>
                    </div>

                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-select-all-filtered-clear" style="border-radius: 7px; font-size: 0.78rem; padding: 3px 10px;" onclick="selectAllFilteredInClearModal()">
                        <i class="fa-solid fa-check-double me-1"></i>เลือกทั้งหมดทุกหน้า
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-unselect-all-clear" style="border-radius: 7px; font-size: 0.78rem; padding: 3px 10px;" onclick="clearAllSelectionInModal()">
                        <i class="fa-solid fa-eraser me-1"></i>ล้างการเลือก
                    </button>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size: 0.8rem;">
                        เลือกแล้ว <strong id="clear-modal-selected-count" class="tabular-num">0</strong> รายการ
                    </span>

                    <div class="d-flex align-items-center gap-1 small text-secondary">
                        <span>แสดงหน้าละ:</span>
                        <select id="clear-modal-page-size" class="form-select form-select-sm tabular-num" style="width: auto; border-radius: 8px; font-size: 0.8rem;" onchange="handleClearModalPageSizeChange(this.value)">
                            <option value="10">10 แถว</option>
                            <option value="25" selected>25 แถว</option>
                            <option value="50">50 แถว</option>
                            <option value="100">100 แถว</option>
                            <option value="200">200 แถว</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="fix-note rounded-0 border-bottom px-3 py-1 d-none" id="clear-modal-asn-note">
                <i class="fa-solid fa-circle-info text-primary"></i>
                <span>การล้างข้อมูล ASN ไม่ย้อนการแก้ไขที่ทำกับ PDF แล้ว ถ้าต้องการเริ่มแก้ไขใหม่ ให้ล้างข้อมูล PDF แล้วดึงข้อมูลใหม่</span>
            </div>

            <!-- Modal Table Body -->
            <div class="modal-body p-0">
                <div class="clear-modal-table-wrap">
                    <table class="table table-hover mb-0 align-middle" id="clear-modal-table">
                        <thead id="clear-modal-thead"></thead>
                        <tbody id="clear-modal-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer: Pagination & Confirm Action -->
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="small text-secondary tabular-num" id="clear-modal-page-info">แสดง 0 - 0 จาก 0 รายการ</span>
                    <nav aria-label="Clear Modal Pagination">
                        <ul class="pagination pagination-sm mb-0" id="clear-modal-pagination"></ul>
                    </nav>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-danger btn-sm px-4 fw-semibold" id="btnConfirmClear" onclick="executeClearJobData()" disabled>
                        <i class="fa-solid fa-trash-can me-1"></i>ลบรายการที่เลือก (<span id="clear-confirm-count" class="tabular-num">0</span>)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ยืนยันการแก้ไขข้อมูลและตัดแยกไฟล์ PDF -->
<div class="modal fade" id="confirmFixModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-wrench text-warning me-2"></i>แก้ไขและแยกไฟล์ PDF
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope">
                    <div class="fix-scope-icon"><i class="fa-solid fa-file-circle-check"></i></div>
                    <div>
                        <div class="fix-scope-title" id="fixModalTitle">บิลที่รอแก้ไข PDF ทั้งหมด</div>
                        <div class="fix-scope-detail" id="fixModalTargetText"></div>
                    </div>
                </div>

                <div class="fix-steps-label">สิ่งที่ระบบจะทำ</div>
                <ol class="fix-steps">
                    <li>ปรับเลข Tax Invoice ให้ตรงกับ ASN
                        <small>เช่น หน้าต่อที่ขึ้น -2 แต่ใน ASN เป็น -1</small></li>
                    <li>ลบยอดรวมท้ายบิลซ้ำในหน้าที่ไม่ใช่หน้าสุดท้ายของบิล
                        <small>เหลือยอดรวมเฉพาะหน้าสุดท้าย</small></li>
                    <li>แยกไฟล์ที่รวมหลายบิล หรือมีหน้าที่ต้องปรับ เป็นไฟล์รายบิล <span class="tabular-num">{เลข Tax Invoice}.pdf</span> ใน <span class="tabular-num">finished/</span>
                        <small>แล้วเทียบกับ ASN ใหม่ทันที บิลที่รอแก้ไข PDF จะตรงกับ ASN ส่วนบิลที่ผิดพลาดยังผิดพลาด และบิลที่รอแก้ไข ASN ยังรอแก้ไข ASN เหมือนเดิม</small></li>
                </ol>

                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span id="fixModalDesc">ไฟล์ PDF ต้นฉบับยังอยู่ครบ ไม่ถูกลบหรือเขียนทับ</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">
                    ยกเลิก
                </button>
                <button type="button" class="btn btn-sm px-3 btn-tb btn-tb-fix" id="btnConfirmFixAction" onclick="executeConfirmedFix()">
                    <i class="fa-solid fa-check me-1"></i> ยืนยันแก้ไข
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ยืนยันล้างข้อมูล VBRP -->
<div class="modal fade" id="vbrpClearModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0">
                    <i class="fa-solid fa-rotate-left text-danger me-2"></i>ล้างข้อมูล VBRP
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fix-scope" style="background: #fef2f2; border-color: #fecaca;">
                    <div class="fix-scope-icon" style="background: #fee2e2; color: #b91c1c;"><i class="fa-solid fa-table-list"></i></div>
                    <div>
                        <div class="fix-scope-title">ลบแถว VBRP ทั้งหมดของ Job นี้</div>
                        <div class="fix-scope-detail tabular-num" id="vbrpClearScope"></div>
                    </div>
                </div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>ถ้า Job นี้เทียบกับ PDF แล้ว ผลเทียบจะกลับไปเป็นสองแหล่ง (PDF กับ ASN) แบบก่อนมี VBRP และปุ่มเทียบกับ PDF จะกดได้อีกเมื่อนำเข้าไฟล์ VBRP ใหม่ ข้อมูล PDF และ ASN ไม่ถูกแตะ</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-sm px-3 btn-danger" id="btnConfirmVbrpClear" onclick="executeVbrpClear()">
                    <i class="fa-solid fa-trash-can me-1"></i> ล้างข้อมูล VBRP
                </button>
            </div>
        </div>
    </div>
</div>

<div id="toast-container">
    <div id="app-toast" class="custom-toast" role="status" aria-live="polite">
        <i class="fa-solid fa-circle-check text-success fs-5"></i>
        <span id="toast-msg"></span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/xlsx.full.min.js?v=<?= filemtime(__DIR__ . '/assets/vendor/xlsx.full.min.js') ?>" async></script>
<script>
    const CURRENT_JOB_ID = <?= (int)$job['id'] ?>;
    const CURRENT_JOB_NAME_CLEAN = <?= json_encode(preg_replace('/[^A-Za-z0-9_\-]/', '_', $job['name']), JSON_UNESCAPED_UNICODE) ?>;
    const ASN_COLUMNS = <?= json_encode(ASN_COLUMNS, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/ui-utils.js?v=<?= filemtime(__DIR__ . '/assets/js/ui-utils.js') ?>"></script>
<?php foreach (['state', 'init', 'pdf-table', 'extraction', 'clear-modal', 'pdf-search-export', 'asn-import', 'vbrp-import', 'asn-table', 'asn-fix-export', 'print', 'dashboard', 'column-highlight'] as $module): ?>
<script src="assets/js/workspace/<?= $module ?>.js?v=<?= filemtime(__DIR__ . '/assets/js/workspace/' . $module . '.js') ?>"></script>
<?php endforeach; ?>
</body>
</html>
