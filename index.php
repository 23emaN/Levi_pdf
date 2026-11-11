<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\JobManager;

$jobManager = new JobManager();
$initialJobs = $jobManager->listJobs();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Levi's - Job Management</title>
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

<div class="main-wrapper">

    <!-- Top Bar -->
    <div class="app-header">
        <div class="app-brand">
            <span class="header-file-pill folder-link" title="คลิกเพื่อเปิดโฟลเดอร์ myfile ใน Explorer" onclick="openFolder({ target: 'root' })">
                <i class="fa-regular fa-folder-open text-primary"></i>
                myfile/ (<strong id="stat-myfile" class="tabular-num text-dark">...</strong> ไฟล์)
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <a href="date_editor.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;" id="menu-date-editor">
                <i class="fa-solid fa-calendar-days text-primary me-1"></i> แก้ไขวันที่ PDF
            </a>
            <a href="read_cn_error01.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
                <i class="fa-solid fa-file-invoice text-danger me-1"></i> read_cn_error01
            </a>
        </div>
    </div>

    <!-- Toolbar Ribbon -->
    <div class="app-toolbar">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="modern-search">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" class="form-control form-control-sm" id="search-jobs" placeholder="ค้นหาชื่อ Job, รหัสรอบบิล...">
                <button type="button" class="btn-clear" id="btn-clear-search" style="display: none;"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button class="btn-create-job" onclick="openCreateJobModal()">
                <i class="fa-solid fa-plus"></i> สร้าง Job ใหม่
            </button>
        </div>
    </div>

    <!-- Full-Height Table Viewport -->
    <div class="table-viewport">
        <table class="table mb-0 align-middle" id="jobs-table">
            <thead>
                <tr>
                    <th style="width: 55px;" class="text-center">#</th>
                    <th style="width: 32%;">ชื่อ Job / โฟลเดอร์รอบบิล</th>
                    <th style="width: 10%;" class="text-center">ไฟล์ PDF</th>
                    <th style="width: 11%;" class="text-center">รายการสินค้า</th>
                    <th style="width: 14%;" class="text-end">ยอดเงินรวม (THB)</th>
                    <th style="width: 18%;">สถานะการเทียบไฟล์ ASN</th>
                    <th style="width: 14%;">สร้างเมื่อ</th>
                    <th style="width: 55px;" class="text-center">ลบ</th>
                </tr>
            </thead>
            <tbody id="jobs-tbody">
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <div class="spinner-border spinner-border-sm text-danger me-2"></div> กำลังโหลดรายการ Job...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Status Bar Footer -->
    <div class="app-statusbar">
        <div>
            <i class="fa-solid fa-folder-open text-primary me-1"></i>
            แสดง <strong id="footer-count" class="tabular-num text-dark">0</strong> Job จากทั้งหมดในระบบ
        </div>
    </div>

</div>

<!-- Modal: สร้าง Job ใหม่ -->
<div class="modal fade" id="createJobModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom pb-3">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-folder-plus text-success me-2"></i>สร้าง Job ใหม่
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="createJobForm" onsubmit="handleCreateJob(event)">
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label for="jobNameInput" class="form-label fw-semibold text-secondary">
                            ชื่อ Job <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control form-control-lg" id="jobNameInput" 
                               placeholder="ชื่อ Job" required autofocus>
                    </div>
                </div>
                <div class="modal-footer border-top pt-3 bg-light">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success btn-sm px-4 fw-semibold" id="btnSubmitCreateJob">
                        <i class="fa-solid fa-check me-1"></i>สร้าง Job ทันที
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: ยืนยันการลบ Job -->
<div class="modal fade" id="deleteJobModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom pb-3 bg-danger bg-opacity-10">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-trash-can text-danger me-2"></i>ยืนยันการลบ Job
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-2">คุณต้องการลบ Job <strong id="deleteJobModalName" class="text-danger"></strong> ใช่หรือไม่?</p>
                <div class="alert alert-danger mb-3 small">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>คำเตือน:</strong> การลบนี้จะลบโฟลเดอร์และข้อมูลทั้งหมดใน Job นี้อย่างถาวร ไม่สามารถกู้คืนได้
                    <div class="mt-2 fw-semibold d-none" id="deleteJobPrintWarning"></div>
                </div>
                <div class="mb-3 small" id="deleteJobSummary">
                    <div class="fw-semibold text-secondary mb-1">ข้อมูลที่จะถูกลบ</div>
                    <div class="text-muted" id="deleteJobSummaryLoading"><span class="spinner-border spinner-border-sm me-1"></span> กำลังนับข้อมูลของ Job นี้...</div>
                    <ul class="mb-0 ps-3 d-none" id="deleteJobSummaryList"></ul>
                </div>
                <label for="deleteJobConfirmInput" class="form-label small mb-1">
                    พิมพ์ชื่อ Job <strong class="delete-job-name-copy" id="deleteJobConfirmName"></strong> ให้ตรงทุกตัวอักษรเพื่อยืนยัน
                </label>
                <input type="text" class="form-control form-control-sm" id="deleteJobConfirmInput" autocomplete="off" spellcheck="false">
            </div>
            <div class="modal-footer border-top pt-3 bg-light">
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-danger btn-sm px-4 fw-semibold" id="btnConfirmDeleteJob" disabled>
                    <i class="fa-solid fa-trash-can me-1"></i><span id="btnConfirmDeleteJobLabel">ลบ Job (3)</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.INITIAL_JOBS = <?= json_encode($initialJobs, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/ui-utils.js?v=<?= filemtime(__DIR__ . '/assets/js/ui-utils.js') ?>"></script>
<script src="assets/js/index.js?v=<?= filemtime(__DIR__ . '/assets/js/index.js') ?>"></script>
</body>
</html>
