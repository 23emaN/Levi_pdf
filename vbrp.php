<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\FileManager;
use App\JobFolders;
use App\JobManager;
use App\VbrpStore;

$jobId = intval($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    header('Location: index.php');
    exit;
}

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

$vbrpCounts = $jobManager->vbrpCounts($jobId);
$vbrpFiles = $jobManager->getVbrpFiles($jobId);
$vbrpUploadCount = $jobManager->waitingCount($jobId, 'vbrp');
$vbrpUploadFolderText = JobFolders::displayPath($jobManager->getJobVbrpUploadDir($job)) . '/';
$vbrpFinishedText = JobFolders::displayPath($jobManager->getJobVbrpFinishedDir($job)) . '/';
$vbrpExcelColumns = implode(' / ', array_keys(VbrpStore::COLUMNS));

// One VBRP data row per table row. 'excel' is the column of the Excel file the cell is read from (VbrpStore::COLUMNS holds
// the same letters); assets/js/vbrp.js has the cells by key, and column-highlight.js paints a column by its position.
const VBRP_COLUMNS = [
    ['key' => '#', 'label' => '#', 'type' => 'number', 'class' => 'text-center', 'width' => 56],
    ['key' => 'combine', 'excel' => 'A', 'label' => 'Combine', 'type' => 'string', 'width' => 220],
    ['key' => 'invoice', 'excel' => 'C', 'label' => 'Invoice', 'type' => 'string', 'width' => 140],
    ['key' => 'delivery_no', 'excel' => 'E', 'label' => 'Delivery No.', 'type' => 'string', 'width' => 140],
    ['key' => 'quantity', 'excel' => 'H', 'label' => 'Quantity', 'type' => 'number', 'class' => 'text-center', 'width' => 100],
    ['key' => 'selling_price', 'excel' => 'CI', 'label' => 'Selling Price', 'type' => 'currency', 'class' => 'text-end', 'width' => 130],
    ['key' => 'total_cost_ex_vat', 'excel' => 'CK', 'label' => 'Total Cost EX VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 160],
    ['key' => 'ean_upc', 'excel' => 'CQ', 'label' => 'EAN/UPC', 'type' => 'string', 'width' => 150],
    ['key' => 'total_vat', 'excel' => 'EM', 'label' => 'Total VAT', 'type' => 'currency', 'class' => 'text-end', 'width' => 120],
    ['key' => 'sheet', 'label' => 'Source', 'type' => 'string', 'width' => 200, 'title' => 'ชื่อชีตที่อ่านแถวนี้มา (ชื่อไฟล์อยู่ในคำอธิบายของเซลล์)'],
];

function renderVbrpHeader(array $columns): string
{
    $out = '';
    foreach ($columns as $col) {
        $class = trim($col['class'] ?? '');
        $title = isset($col['excel']) ? 'Excel column ' . $col['excel'] : ($col['title'] ?? null);
        $attributes = ' data-col="' . htmlspecialchars($col['key']) . '" data-type="' . $col['type'] . '"'
            . ($class !== '' ? ' class="' . $class . '"' : '')
            . (isset($col['width']) ? ' style="width: ' . $col['width'] . 'px;"' : '')
            . ($title !== null ? ' title="' . htmlspecialchars($title) . '"' : '');
        $out .= "<th{$attributes}>" . htmlspecialchars($col['label']) . " <i class=\"fa-solid fa-sort sort-icon\"></i></th>\n";
    }
    return $out;
}

function renderVbrpPlaceholderRows(int $columnCount, int $rowCount = 18): string
{
    $out = '';
    $emptyCells = str_repeat('<td></td>', $columnCount - 1);
    for ($i = 1; $i <= $rowCount; $i++) {
        $out .= "<tr class=\"placeholder-grid-row\"><td class=\"text-center text-muted tabular-num opacity-40\">{$i}</td>{$emptyCells}</tr>";
    }
    return $out;
}

function renderVbrpFinishedMenu(array $files, int $totalRows): string
{
    if (empty($files)) {
        return '<li><span class="dropdown-item-text text-muted small py-2"><i class="fa-regular fa-folder-open me-2"></i>ยังไม่มีไฟล์ที่อัปโหลดผ่านใน finished</span></li>'
            . '<li><hr class="dropdown-divider my-1"></li>'
            . '<li><button type="button" class="dropdown-item d-flex align-items-center text-primary" data-vbrp-open-folder="1"><i class="fa-regular fa-folder-open me-2"></i>เปิดโฟลเดอร์ finished ใน Explorer</button></li>';
    }
    $out = '<li><button type="button" class="dropdown-item d-flex align-items-center justify-content-between active" data-vbrp-file="">'
        . '<span><i class="fa-solid fa-layer-group me-2 text-primary"></i>แสดงทุกไฟล์รวมกัน</span>'
        . '<span class="badge rounded-pill text-bg-light tabular-num ms-3">' . number_format($totalRows) . ' รายการ</span>'
        . '</button></li>'
        . '<li><hr class="dropdown-divider my-1"></li>'
        . '<li><h6 class="dropdown-header py-1 text-muted">ไฟล์ใน finished (' . number_format(count($files)) . ' ไฟล์)</h6></li>';
    foreach ($files as $file) {
        $name = htmlspecialchars((string)$file['filename']);
        $rows = number_format((int)$file['row_count']);
        $out .= '<li><button type="button" class="dropdown-item d-flex align-items-center justify-content-between" data-vbrp-file="' . $name . '" title="' . $name . '">'
            . '<span class="text-truncate vbrp-finished-item-name"><i class="fa-regular fa-file-excel text-success me-2"></i>' . $name . '</span>'
            . '<span class="badge rounded-pill text-bg-light tabular-num ms-3">' . $rows . ' รายการ</span>'
            . '</button></li>';
    }
    $out .= '<li><hr class="dropdown-divider my-1"></li>'
        . '<li><button type="button" class="dropdown-item d-flex align-items-center text-primary" data-vbrp-open-folder="1"><i class="fa-regular fa-folder-open me-2"></i>เปิดโฟลเดอร์ finished ใน Explorer</button></li>';
    return $out;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($job['name']) ?> - VBRP - Levi's PDF Data Extractor</title>
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
        <div class="loading-title" id="loading-overlay-title">กำลังโหลดข้อมูล VBRP...</div>
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
                <i class="fa-solid fa-stop me-1"></i> หยุดหลังจบไฟล์นี้
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
            <span class="header-file-pill folder-link" title="คลิกเพื่อเปิดโฟลเดอร์ <?= htmlspecialchars($vbrpUploadFolderText) ?> ใน Explorer (นับเฉพาะไฟล์ที่ยังไม่ได้ดึงข้อมูล)" onclick="openJobFolder('vbrp_upload')">
                <i class="fa-regular fa-folder-open me-1 text-primary"></i>
                invoice/vbrp/ (ใหม่ <strong id="vbrp-upload-count" class="tabular-num text-dark"><?= number_format($vbrpUploadCount) ?></strong> ไฟล์)
            </span>
        </div>

        <div class="header-tabs">
            <button class="header-tab-btn active" type="button">
                <i class="fa-solid fa-table-list vbrp-accent me-1"></i> ดึงข้อมูลจากไฟล์ VBRP (Excel)
            </button>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="header-file-pill"><i class="fa-solid fa-table-list vbrp-accent"></i> VBRP</span>
        </div>
    </div>

    <div id="vbrp-section" data-step="<?= $vbrpUploadCount > 0 ? 'extract' : 'idle' ?>">
        <div class="dashboard-notice vbrp-read-notice" id="vbrp-read-notice">
            <i class="fa-solid fa-circle-info text-primary"></i>
            <span>ระบบจะอ่านข้อมูลตั้งแต่ <strong>แถวที่ <?= VbrpStore::DATA_START_ROW ?></strong> ลงไป จากคอลัมน์ <strong><?= htmlspecialchars($vbrpExcelColumns) ?></strong> ของทุกชีตในไฟล์ Excel</span>
        </div>

        <div class="app-toolbar toolbar-stacked">
            <div class="toolbar-row">
                <button class="btn btn-sm px-3 btn-tb btn-tb-accent btn-step-extract" id="vbrp-start" type="button">
                    <i class="fa-solid fa-play me-1"></i> ดึงข้อมูลจากโฟลเดอร์ invoice/vbrp
                </button>
                <button class="btn btn-outline-danger btn-sm" id="vbrp-stop" type="button" style="display: none;">
                    <i class="fa-solid fa-stop me-1"></i> หยุด
                </button>
                <button class="btn btn-sm btn-tb" id="vbrp-open-upload" type="button" onclick="openJobFolder('vbrp_upload')" title="เปิดโฟลเดอร์ <?= htmlspecialchars($vbrpUploadFolderText) ?> ใน Explorer">
                    <i class="fa-regular fa-folder-open me-1"></i> invoice/vbrp
                </button>
                <div class="dropdown d-inline-block" id="vbrp-finished-dropdown">
                    <button class="btn btn-sm btn-tb dropdown-toggle" id="vbrp-open-finished" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ดูรายการไฟล์ที่อัปโหลดผ่านแล้วใน VBRP/finished">
                        <i class="fa-regular fa-folder me-1"></i>
                        <span id="vbrp-finished-label">finished</span>
                        <span class="badge rounded-pill text-bg-secondary ms-1 tabular-num" id="vbrp-finished-count"><?= number_format($vbrpCounts['files']) ?></span>
                    </button>
                    <ul class="dropdown-menu shadow vbrp-finished-menu" id="vbrp-finished-menu" aria-labelledby="vbrp-open-finished">
                        <?= renderVbrpFinishedMenu($vbrpFiles, $vbrpCounts['rows']) ?>
                    </ul>
                </div>

                <div class="modern-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="form-control form-control-sm" id="vbrp-search" placeholder="ค้นหาข้อมูลในตาราง..." autocomplete="off">
                    <button type="button" class="btn-clear" id="vbrp-clear-search" style="display: none;" aria-label="ล้างคำค้นหา"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="toolbar-end d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-tb" id="vbrp-export" type="button" disabled>
                        <i class="fa-solid fa-file-excel me-1"></i> Excel (.xlsx)
                    </button>
                    <button class="btn btn-sm btn-tb btn-tb-quiet" id="vbrp-clear" type="button">
                        <i class="fa-solid fa-rotate-left me-1"></i> ล้างข้อมูล VBRP
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table class="table mb-0 align-middle" id="vbrp-table">
                <thead>
                    <tr>
                        <?= renderVbrpHeader(VBRP_COLUMNS) ?>
                    </tr>
                </thead>
                <tbody id="vbrp-tbody">
                    <?php if ($vbrpCounts['rows'] === 0): ?>
                        <?= renderVbrpPlaceholderRows(count(VBRP_COLUMNS)) ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="app-statusbar">
        <div class="d-flex align-items-center gap-3" id="vbrp-status-counts">
            <div class="text-nowrap">
                <i class="fa-regular fa-file-excel text-success me-1"></i>
                ไฟล์: <strong id="vbrp-files-count" class="tabular-num text-dark"><?= number_format($vbrpCounts['files']) ?></strong>
            </div>
            <span class="text-muted">|</span>
            <div class="text-nowrap">
                <i class="fa-solid fa-list-check vbrp-accent me-1"></i>
                รายการ: <strong id="vbrp-rows-count" class="tabular-num text-dark"><?= number_format($vbrpCounts['rows']) ?></strong>
            </div>
        </div>
        <a href="#" class="folder-link small" title="คลิกเพื่อเปิดโฟลเดอร์ VBRP ของ Job นี้ใน Explorer" onclick="openJobFolder('vbrp'); return false;">
            <i class="fa-regular fa-folder-closed me-1 text-primary"></i>
            โฟลเดอร์: <span class="tabular-num"><?= htmlspecialchars(JobFolders::displayPath($jobManager->getJobVbrpDir($job, false))) ?>/</span>
        </a>
    </div>

</div>

<div class="modal fade" id="vbrpNoticeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-2 px-3 bg-light d-flex align-items-center justify-content-between">
                <h6 class="modal-title fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-info text-primary" id="vbrpNoticeIcon"></i>
                    <span id="vbrpNoticeTitle">แจ้งเตือน</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="fw-semibold text-dark mb-2" id="vbrpNoticeMessage"></div>
                <div class="cn-notice-detail" id="vbrpNoticeDetail"></div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb d-none" id="vbrpNoticeAction"></button>
                <button type="button" class="btn btn-primary btn-sm px-4 fw-semibold" data-bs-dismiss="modal" style="border-radius: 8px;">ตกลง</button>
            </div>
        </div>
    </div>
</div>

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
                    <div class="fix-scope-icon" style="background: #fee2e2; color: #b91c1c;"><i class="fa-solid fa-trash-can"></i></div>
                    <div>
                        <div class="fix-scope-title">ลบ <span class="tabular-num" id="vbrpClearRows">0</span> รายการ จาก <span class="tabular-num" id="vbrpClearFiles">0</span> ไฟล์ ออกจาก Job นี้</div>
                        <div class="fix-scope-detail">ตารางจะว่าง และนับใหม่ตั้งแต่ศูนย์</div>
                    </div>
                </div>
                <div class="fix-note">
                    <i class="fa-solid fa-circle-info text-primary"></i>
                    <span>ไฟล์ Excel ไม่ถูกลบ ไฟล์ต้นฉบับยังอยู่ในโฟลเดอร์ <?= htmlspecialchars($vbrpUploadFolderText) ?> ถ้าต้องการดึงข้อมูลใหม่ กดดึงข้อมูลอีกครั้ง</span>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-3 bg-light d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-sm px-3 btn-tb" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-danger btn-sm px-3 fw-semibold" id="vbrpClearConfirm" style="border-radius: 8px;">
                    <i class="fa-solid fa-trash-can me-1"></i> ล้างข้อมูล
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/xlsx.full.min.js?v=<?= filemtime(__DIR__ . '/assets/vendor/xlsx.full.min.js') ?>" async></script>
<script>
    const CURRENT_JOB_ID = <?= (int)$job['id'] ?>;
    const VBRP_JOB_NAME_CLEAN = <?= json_encode(preg_replace('/[^A-Za-z0-9_\-]/', '_', $job['name']), JSON_UNESCAPED_UNICODE) ?>;
    const VBRP_COLUMNS = <?= json_encode(VBRP_COLUMNS, JSON_UNESCAPED_UNICODE) ?>;
    const VBRP_INITIAL_FILES = <?= json_encode($vbrpFiles, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/ui-utils.js?v=<?= filemtime(__DIR__ . '/assets/js/ui-utils.js') ?>"></script>
<script src="assets/js/vbrp.js?v=<?= filemtime(__DIR__ . '/assets/js/vbrp.js') ?>"></script>
<script src="assets/js/workspace/column-highlight.js?v=<?= filemtime(__DIR__ . '/assets/js/workspace/column-highlight.js') ?>"></script>
</body>
</html>
