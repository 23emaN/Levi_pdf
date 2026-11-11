<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\DateEditStore;

$zipAvailable = class_exists('ZipArchive');
$uploadPath = DateEditStore::displayPath(DateEditStore::UPLOAD);
$finishedPath = DateEditStore::displayPath(DateEditStore::FINISHED);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขวันที่ PDF - Levi's</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
    <style>
        .de-steps { display: flex; gap: 12px; flex-wrap: wrap; padding: 12px 24px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; flex-shrink: 0; }
        .de-card { flex: 1 1 280px; min-width: 0; display: flex; flex-direction: column; gap: 6px; padding: 10px 12px;
                   border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .de-card.is-current { border-color: #1d4ed8; box-shadow: 0 0 0 1px #1d4ed8; background: #eff6ff; }
        .de-card.is-done { border-color: #86efac; }
        .de-card-head { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .de-card-title { font-weight: 600; font-size: 0.9rem; }
        .de-card-count { margin-left: auto; font-size: 0.8rem; color: #6b7280; }
        .de-card-note { font-size: 0.8rem; color: #6b7280; }
        .de-card-actions { margin-top: auto; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .de-folder-path { font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; color: #9ca3af; overflow-wrap: anywhere; }
        .de-step { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%;
                   background: #94a3b8; color: #fff; font-size: 0.75rem; font-weight: 700; flex: none; }
        .de-card.is-current .de-step { background: #1d4ed8; }
        .de-card.is-done .de-step { background: #15803d; }
        #de-start:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }
        @media (max-width: 991px) {
            body { overflow: auto; }
            .main-wrapper { width: 100%; height: auto; min-height: 100vh; }
            .table-viewport { flex: none; max-height: 70vh; min-height: 320px; }
        }
    </style>
</head>
<body>

<div class="main-wrapper">

    <div class="app-header">
        <div class="app-brand">
            <a href="index.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;" title="กลับหน้ารวม Job">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <span class="header-title">
                <i class="fa-solid fa-calendar-days text-primary me-1"></i> แก้ไขวันที่ PDF
            </span>
        </div>
    </div>

    <div class="de-steps">
        <section class="de-card" data-de-card="1">
            <div class="de-card-head">
                <span class="de-step">1</span>
                <span class="de-card-title">ใส่ไฟล์ PDF</span>
                <span class="de-card-count">มี <strong class="tabular-num text-dark" id="de-upload-count">-</strong> ไฟล์</span>
            </div>
            <div class="de-card-note">กด "เปิดโฟลเดอร์" แล้ววางไฟล์ PDF หรือโฟลเดอร์ลงไป รายการจะอัปเดตเองภายในไม่กี่วินาที</div>
            <div class="de-folder-path" title="ตำแหน่งโฟลเดอร์ในเครื่อง"><?= htmlspecialchars($uploadPath) ?></div>
            <div class="de-card-actions">
                <button type="button" class="btn btn-primary btn-sm" data-de-open="upload"><i class="fa-regular fa-folder-open me-1"></i>เปิดโฟลเดอร์</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="de-scan"><i class="fa-solid fa-rotate me-1"></i>รีเฟรชรายการ</button>
                <button type="button" class="btn btn-outline-danger btn-sm ms-auto" data-de-clear="upload" title="ลบไฟล์ต้นฉบับทั้งหมดในโฟลเดอร์นี้"><i class="fa-solid fa-trash-can me-1"></i>ลบไฟล์ทั้งหมด</button>
            </div>
            <div class="small text-muted" id="de-scan-info"></div>
        </section>

        <section class="de-card" data-de-card="2">
            <div class="de-card-head">
                <span class="de-step">2</span>
                <span class="de-card-title">เลือกวันที่และเริ่มแก้</span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <label for="de-date" class="small fw-semibold text-dark mb-0">วันที่ใหม่ของเอกสาร</label>
                <input type="date" class="form-control form-control-sm" id="de-date" style="width: 160px;">
            </div>
            <div class="de-card-note" id="de-date-preview"></div>
            <div class="de-card-actions">
                <button type="button" class="btn-create-job" id="de-start" disabled>
                    <i class="fa-solid fa-pen-to-square"></i> <span id="de-start-label">เริ่มแก้วันที่</span>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm d-none" id="de-stop">
                    <i class="fa-solid fa-stop me-1"></i> หยุด
                </button>
            </div>
            <div class="small text-muted" id="de-start-hint"></div>
        </section>

        <section class="de-card" data-de-card="3">
            <div class="de-card-head">
                <span class="de-step">3</span>
                <span class="de-card-title">รับไฟล์ที่แก้แล้ว</span>
                <span class="de-card-count">มี <strong class="tabular-num text-dark" id="de-finished-count">-</strong> ไฟล์</span>
            </div>
            <div class="de-card-note">ชื่อไฟล์และโฟลเดอร์ย่อยเหมือนไฟล์ต้นฉบับ หากแก้ซ้ำ ไฟล์ชื่อเดิมในโฟลเดอร์นี้จะถูกเขียนทับ</div>
            <div class="de-folder-path" title="ตำแหน่งโฟลเดอร์ในเครื่อง"><?= htmlspecialchars($finishedPath) ?></div>
            <div class="de-card-actions">
                <button type="button" class="btn btn-primary btn-sm" data-de-open="finished"><i class="fa-regular fa-folder-open me-1"></i>เปิดโฟลเดอร์</button>
                <?php if ($zipAvailable): ?>
                <a class="btn btn-outline-success btn-sm" id="de-zip" href="date_editor_download.php"><i class="fa-solid fa-file-zipper me-1"></i>ดาวน์โหลด ZIP</a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-danger btn-sm ms-auto" data-de-clear="finished" title="ลบไฟล์ที่แก้แล้วทั้งหมดในโฟลเดอร์นี้"><i class="fa-solid fa-trash-can me-1"></i>ลบไฟล์ทั้งหมด</button>
            </div>
        </section>
    </div>

    <div class="px-3 py-2 border-bottom bg-white d-none" id="de-progress-wrap">
        <div class="small mb-1" id="de-progress-text"></div>
        <div class="progress" style="height: 6px;">
            <div class="progress-bar bg-success" id="de-progress-bar" style="width: 0%"></div>
        </div>
    </div>

    <div class="table-viewport">
        <table class="table mb-0 align-middle" id="de-table">
            <thead>
                <tr>
                    <th style="width: 55px;" class="text-center">#</th>
                    <th style="width: 22%;">โฟลเดอร์ย่อย</th>
                    <th>ชื่อไฟล์</th>
                    <th style="width: 8%;" class="text-center">หน้า</th>
                    <th style="width: 13%;">วันที่ในไฟล์เดิม</th>
                    <th style="width: 13%;">วันที่ใหม่</th>
                    <th style="width: 18%;">ผลลัพธ์</th>
                </tr>
            </thead>
            <tbody id="de-tbody">
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <div class="spinner-border spinner-border-sm text-danger me-2"></div> กำลังโหลดรายการไฟล์...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="app-statusbar">
        <div>
            <i class="fa-regular fa-file-pdf text-danger me-1"></i>
            ทั้งหมด <strong class="tabular-num text-dark" id="de-count">0</strong> ไฟล์
            <span class="ms-3 text-success" id="de-ok-count"></span>
            <span class="ms-2 text-warning" id="de-warn-count"></span>
            <span class="ms-2 text-danger" id="de-err-count"></span>
        </div>
    </div>

    <div id="app-toast" class="custom-toast" role="status" aria-live="polite">
        <i class="fa-solid fa-circle-check text-success fs-5"></i>
        <span id="toast-msg"></span>
    </div>
</div>

<!-- Modal: ยืนยันการล้างโฟลเดอร์ -->
<div class="modal fade" id="deClearModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom pb-3 bg-danger bg-opacity-10">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-trash-can me-2"></i>ยืนยันการลบไฟล์
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-2">ลบ<strong class="text-danger" id="deClearFolder"></strong>ทั้งหมด <strong class="tabular-num" id="deClearCount"></strong> ไฟล์ (รวมโฟลเดอร์ย่อย)</p>
                <div class="alert alert-danger small mb-0"><i class="fa-solid fa-triangle-exclamation me-1"></i>ลบแล้วกู้คืนไม่ได้</div>
            </div>
            <div class="modal-footer border-top pt-3 bg-light">
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-danger btn-sm px-4 fw-semibold" id="deClearConfirm">
                    <i class="fa-solid fa-trash-can me-1"></i>ลบไฟล์
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const DE_FOLDERS = <?= json_encode(['upload' => $uploadPath, 'finished' => $finishedPath], JSON_UNESCAPED_UNICODE) ?>;
    const DE_FOLDER_LABELS = { upload: 'ไฟล์ต้นฉบับ', finished: 'ไฟล์ที่แก้ไขแล้ว' };
</script>
<script src="assets/js/ui-utils.js?v=<?= filemtime(__DIR__ . '/assets/js/ui-utils.js') ?>"></script>
<script src="assets/js/date-editor.js?v=<?= filemtime(__DIR__ . '/assets/js/date-editor.js') ?>"></script>
</body>
</html>
