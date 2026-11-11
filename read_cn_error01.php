<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Levi's - read_cn_error01.php</title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- App Stylesheet -->
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<div class="main-wrapper">
    <!-- Top Header -->
    <div class="app-header">
        <div class="app-brand">
            <div class="header-title">
                <i class="fa-solid fa-triangle-exclamation text-danger"></i>
                <span>read_cn_error01 (ตรวจสอบไฟล์ใบลดหนี้ที่มี -)</span>
            </div>
            <span class="header-file-pill">
                <i class="fa-regular fa-folder-open me-1 text-primary"></i>
                myfile/ (<span id="stat-myfile">...</span> ไฟล์)
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Status Badge after completed -->
            <span id="completion-badge" class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="display: none;">
                <i class="fa-solid fa-circle-check me-1"></i> <span id="completion-text">ประมวลผลเสร็จสิ้น</span>
            </span>

            <a href="index.php" class="btn btn-outline-primary btn-sm" style="border-radius: 6px; font-size: 0.82rem; padding: 4px 12px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> กลับไปหน้าหลัก (Detailed Data)
            </a>
        </div>
    </div>

    <!-- Toolbar Ribbon -->
    <div class="app-toolbar">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <select class="form-select form-select-sm" id="select-folder" style="width: auto; min-width: 190px; border-radius: 8px;">
                <option value="myfile" selected>myfile/ (ทั้งหมด <span class="opt-count-myfile">-</span> ไฟล์)</option>
                <option value="cn">cn/ (<span class="opt-count-cn">0</span> ไฟล์)</option>
                <option value="invoice">invoice/ (<span class="opt-count-invoice">0</span> ไฟล์)</option>
            </select>

            <select class="form-select form-select-sm" id="select-filter-type" style="width: auto; min-width: 270px; border-radius: 8px;">
                <option value="legacy_error" selected>ไฟล์ที่มี - ตามเงื่อนไขเดิม (Original Error)</option>
                <option value="has_dash">เฉพาะเลขที่เอกสารที่มีขีด (-) เช่น 93250403-1</option>
                <option value="all">ใบลดหนี้ทั้งหมด (All Credit Notes)</option>
            </select>

            <button class="btn btn-primary btn-sm px-3" id="btn-start" style="border-radius: 8px; font-weight: 500;">
                <i class="fa-solid fa-play me-1"></i> เริ่มอ่านเอกสาร
            </button>
            <button class="btn btn-outline-danger btn-sm" id="btn-stop" style="display:none; border-radius: 8px;">
                <i class="fa-solid fa-stop me-1"></i> หยุด
            </button>

            <div class="modern-search ms-1">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" class="form-control form-control-sm" id="global-search" placeholder="ค้นหา DOCNO, ชื่อไฟล์...">
                <button type="button" class="btn-clear" id="btn-clear-search" style="display: none;"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <span class="badge bg-light text-secondary border px-2 py-1 ms-1" id="counter-badge" style="display:none;">
                แสดง <span id="visible-count">0</span> รายการ
            </span>

            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 ms-1" id="sum-badge" style="display:none;">
                ยอดรวม: ฿<span id="sum-amount">0.00</span>
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button class="btn-excel-copy" id="btn-copy-excel" disabled>
                <i class="fa-solid fa-copy"></i> Copy for Excel
            </button>
            <button class="btn btn-success btn-sm" id="btn-export-excel" style="border-radius: 6px; font-weight: 500;" disabled>
                <i class="fa-solid fa-file-excel me-1"></i> Excel (.xlsx)
            </button>
        </div>
    </div>

    <!-- Progress Line -->
    <div id="progress-wrapper" style="display: none; padding: 8px 24px 6px 24px; background: #ffffff; border-bottom: 1px solid #f1f5f9;">
        <div class="d-flex justify-content-between text-muted small mb-1">
            <span id="progress-label">กำลังเตรียมการ...</span>
            <span id="progress-pct" class="fw-bold text-primary">0%</span>
        </div>
        <div class="progress" style="height: 6px; border-radius: 9999px;">
            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%;"></div>
        </div>
    </div>

    <!-- Table Container (Strictly 3 Original Columns: DOCNO, FILENAME, AMOUNT) -->
    <div class="table-viewport" id="table-container">
        <table id="cn-table">
            <thead>
                <tr>
                    <th data-col="row_no" data-type="number" style="width: 60px;" class="text-center">#</th>
                    <th data-col="doc_no" data-type="string" style="width: 180px;">DOCNO <i class="fa-solid fa-sort sort-icon"></i></th>
                    <th data-col="filename" data-type="string" style="width: 520px;">FILENAME <i class="fa-solid fa-sort sort-icon"></i></th>
                    <th data-col="amount" data-type="currency" class="text-end" style="width: 220px; color: #b91c1c;">AMOUNT <i class="fa-solid fa-sort sort-icon"></i></th>
                </tr>
            </thead>
            <tbody id="cn-tbody">
                <?php for ($i = 1; $i <= 30; $i++): ?>
                <tr>
                    <td class="text-center text-muted" style="color: #94a3b8; font-weight: 500; background-color: #f8fafc;"><?= $i ?></td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Non-blocking Toast Notification -->
<div id="toast-container">
    <div id="app-toast" class="custom-toast">
        <i class="fa-solid fa-circle-check text-success fs-5"></i>
        <span id="toast-msg">คัดลอกข้อมูลสำเร็จ</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/xlsx.full.min.js?v=<?= filemtime(__DIR__ . '/assets/vendor/xlsx.full.min.js') ?>" async></script>
<script src="assets/js/ui-utils.js"></script>
<script src="assets/js/cn-error.js"></script>
</body>
</html>
