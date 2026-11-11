<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\CoverSheet;
use App\JobFolders;
use App\JobManager;

$jobId = intval($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    header('Location: index.php');
    exit;
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

$settings = (new CoverSheet($jobManager))->settings();
$coverFolder = JobFolders::displayPath($jobManager->getJobCoverDir($job, false)) . '/';
$fields = [
    'contact' => ['label' => 'ผู้ติดต่อกรณีพบปัญหา', 'placeholder' => 'เช่น คุณปาล์ม'],
    'mobile' => ['label' => 'โทรศัพท์มือถือ', 'placeholder' => 'เช่น 061-403-8024'],
    'case_label' => ['label' => 'กรณีจัดรายการโปรดระบุชื่องาน', 'placeholder' => 'เช่น สินค้าจัดรายการ'],
    'date' => ['label' => 'วันที่ (เว้นว่างได้)', 'placeholder' => 'เช่น 10/11/2569'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบปะหน้า - <?= htmlspecialchars($job['name']) ?></title>
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
        <div class="loading-title" id="loading-overlay-title">กำลังโหลด...</div>
        <div class="loading-subtitle" id="loading-overlay-sub">กรุณารอสักครู่</div>
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
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="header-file-pill"><i class="fa-solid fa-print text-success"></i> ใบปะหน้า</span>
        </div>
    </div>

    <main class="cover-body">
        <div class="cover-grid">
            <section class="cover-panel">
                <h2 class="cover-heading">ส่วนหัวของใบปะหน้า</h2>
                <form id="cover-form" autocomplete="off">
                    <?php foreach ($fields as $name => $field): ?>
                    <label class="cover-field">
                        <span><?= htmlspecialchars($field['label']) ?></span>
                        <input type="text" class="form-control form-control-sm" name="<?= $name ?>" maxlength="60" value="<?= htmlspecialchars($settings[$name]) ?>" placeholder="<?= htmlspecialchars($field['placeholder']) ?>">
                    </label>
                    <?php endforeach; ?>
                    <p class="cover-note">ค่าที่กรอกจะถูกจำไว้ใช้ครั้งถัดไป รหัสร้านค้า CDS บริษัท และเบอร์โทรศัพท์สำนักงานพิมพ์มากับแบบฟอร์มแล้ว</p>
                    <button type="submit" class="btn btn-sm px-3 btn-tb btn-tb-go w-100" id="cover-generate" disabled>
                        <i class="fa-solid fa-file-pdf me-1"></i> สร้างใบปะหน้า PDF
                    </button>
                </form>

                <h2 class="cover-heading mt-4">ไฟล์ที่สร้างแล้ว</h2>
                <ul class="cover-files" id="cover-files"><li class="text-secondary">กำลังโหลด...</li></ul>
                <a href="#" class="folder-link small" onclick="openJobFolder('cover'); return false;" title="เปิดโฟลเดอร์ใบปะหน้าใน Explorer">
                    <i class="fa-regular fa-folder-open me-1 text-primary"></i><span class="tabular-num"><?= htmlspecialchars($coverFolder) ?></span>
                </a>
            </section>

            <section class="cover-panel">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h2 class="cover-heading mb-0">เอกสารที่จะพิมพ์</h2>
                    <button type="button" class="btn btn-sm px-3 btn-tb ms-auto" id="cover-refresh">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> โหลดใหม่
                    </button>
                </div>
                <p class="cover-note">เฉพาะ Tax Invoice ที่สมบูรณ์ (รวมแก้ไขแล้ว) และใบลดหนี้ที่สมบูรณ์ของ Job นี้ เรียงตามสาขาแล้วตามเลขเอกสาร ช่องที่ไม่มีเอกสารเว้นว่าง แต่ละสาขาขึ้นหน้าใหม่ หน้าละ 19 แถว</p>
                <div id="cover-warnings"></div>
                <div class="cover-stats" id="cover-stats"></div>
                <div class="table-container cover-table">
                    <table class="table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 56px;">#</th>
                                <th>รหัสสาขา</th>
                                <th>ชื่อสาขา</th>
                                <th class="text-end">Tax Invoice</th>
                                <th class="text-end">Credit Note</th>
                                <th class="text-end">หน้า</th>
                            </tr>
                        </thead>
                        <tbody id="cover-stores"><tr><td colspan="6" class="text-center text-secondary py-4">กำลังโหลด...</td></tr></tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>

    <div class="app-statusbar">
        <div>
            <i class="fa-solid fa-hashtag text-primary me-1"></i>
            Job <strong class="tabular-num text-dark"><?= (int)$job['id'] ?></strong>
        </div>
    </div>
</div>

<div id="app-toast" class="custom-toast" role="status" aria-live="polite">
    <i class="fa-solid fa-circle-check text-success fs-5"></i>
    <span id="toast-msg"></span>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const CURRENT_JOB_ID = <?= (int)$job['id'] ?>;
</script>
<script src="assets/js/ui-utils.js?v=<?= filemtime(__DIR__ . '/assets/js/ui-utils.js') ?>"></script>
<script src="assets/js/cover-sheet.js?v=<?= filemtime(__DIR__ . '/assets/js/cover-sheet.js') ?>"></script>
</body>
</html>
