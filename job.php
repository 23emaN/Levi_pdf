<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

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

$statusCounts = $jobManager->statusCounts($jobId);
$cnCounts = $jobManager->creditNoteCounts($jobId);
// $vbrpCounts = $jobManager->vbrpCounts($jobId);

$systems = [
    [
        'key' => 'tax-invoice',
        'href' => 'workspace.php?job_id=' . $jobId,
        'title' => 'TAX INVOICE',
        'icon' => 'fa-file-invoice',
        'tone' => '--tone-bg: #fee2e2; --tone-fg: #b91c1c; --tone-bd: #fca5a5;',
        'desc' => 'อ่านใบกำกับภาษี เทียบกับ ASN แก้ไข และสั่งพิมพ์',
        'pills' => [
            ['icon' => 'fa-regular fa-file-lines', 'label' => 'PDF', 'value' => $statusCounts['pdf_files'], 'unit' => 'ไฟล์'],
            ['icon' => 'fa-solid fa-list-check', 'label' => 'รายการ', 'value' => $statusCounts['pdf_rows'], 'unit' => 'รายการ'],
            ['icon' => 'fa-solid fa-file-excel', 'label' => 'ASN', 'value' => $statusCounts['asn_files'], 'unit' => 'ไฟล์'],
        ],
    ],
    [
        'key' => 'credit-note',
        'href' => 'credit_note.php?job_id=' . $jobId,
        'title' => 'CREDIT NOTE',
        'icon' => 'fa-file-circle-minus',
        'tone' => '--tone-bg: #e0e7ff; --tone-fg: #4338ca; --tone-bd: #a5b4fc;',
        'desc' => 'อ่านใบลดหนี้ PDF และเทียบกับ ASN',
        'pills' => [
            ['icon' => 'fa-regular fa-file-lines', 'label' => 'PDF', 'value' => $cnCounts['files'], 'unit' => 'ไฟล์'],
            ['icon' => 'fa-solid fa-receipt', 'label' => 'ใบลดหนี้', 'value' => $cnCounts['documents'], 'unit' => 'ใบ'],
            ['icon' => 'fa-solid fa-file-excel', 'label' => 'ASN', 'value' => isset($cnCounts['asn']) ? $cnCounts['asn']['files'] : 0, 'unit' => 'ชีต'],
        ],
    ],
    [
        'key' => 'cover-sheet',
        'href' => 'cover_sheet.php?job_id=' . $jobId,
        'title' => 'ใบปะหน้า',
        'icon' => 'fa-print',
        'tone' => '--tone-bg: #dcfce7; --tone-fg: #15803d; --tone-bd: #86efac;',
        'desc' => 'พิมพ์ใบปะหน้าจาก Tax Invoice และใบลดหนี้ที่เทียบแล้วสมบูรณ์ เรียงตามสาขา',
        'pills' => [],
    ],
    // [
    //     'key' => 'vbrp',
    //     'href' => 'vbrp.php?job_id=' . $jobId,
    //     'title' => 'VBRP',
    //     'icon' => 'fa-table-list',
    //     'tone' => '--tone-bg: #d1fae5; --tone-fg: #047857; --tone-bd: #6ee7b7;',
    //     'desc' => 'อ่านไฟล์ Excel VBRP ตั้งแต่แถวที่ 4 ลงไป',
    //     'pills' => [
    //         ['icon' => 'fa-regular fa-file-excel', 'label' => 'ไฟล์', 'value' => $vbrpCounts['files'], 'unit' => 'ไฟล์'],
    //         ['icon' => 'fa-solid fa-table', 'label' => 'รายการ', 'value' => $vbrpCounts['rows'], 'unit' => 'รายการ'],
    //     ],
    // ],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($job['name']) ?> - Levi's PDF Data Extractor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>

<div class="main-wrapper">

    <div class="app-header">
        <div class="app-brand">
            <a href="index.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;" title="กลับหน้ารวม Job">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <span class="header-title">
                <i class="fa-solid fa-folder text-warning me-1"></i> <?= htmlspecialchars($job['name']) ?>
            </span>
        </div>
    </div>

    <main class="portal-body">
        <div class="portal-wrap">
            <h1 class="portal-lead">เลือกงานที่ต้องการทำใน Job นี้</h1>
            <nav class="portal-cards" aria-label="งานของ Job">
                <?php foreach ($systems as $system): ?>
                <a class="portal-card" id="portal-<?= $system['key'] ?>" href="<?= htmlspecialchars($system['href']) ?>" style="<?= $system['tone'] ?>">
                    <span class="portal-card-head">
                        <span class="portal-icon"><i class="fa-solid <?= $system['icon'] ?>"></i></span>
                        <span class="portal-title"><?= $system['title'] ?></span>
                        <i class="fa-solid fa-arrow-right portal-go" aria-hidden="true"></i>
                    </span>
                    <span class="portal-desc"><?= htmlspecialchars($system['desc']) ?></span>
                    <span class="portal-pills">
                        <?php foreach ($system['pills'] as $pill): ?>
                        <span class="portal-pill"><i class="<?= $pill['icon'] ?>"></i><?= $pill['label'] ?> <strong class="tabular-num"><?= number_format($pill['value']) ?></strong> <?= $pill['unit'] ?></span>
                        <?php endforeach; ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </main>

    <div class="app-statusbar">
        <div>
            <i class="fa-solid fa-hashtag text-primary me-1"></i>
            Job <strong class="tabular-num text-dark"><?= (int)$job['id'] ?></strong>
        </div>
        <a href="#" class="folder-link small" title="คลิกเพื่อเปิดโฟลเดอร์ของ Job นี้ใน Explorer" onclick="openJobFolder('job'); return false;">
            <i class="fa-regular fa-folder-closed me-1 text-primary"></i>
            โฟลเดอร์: <span class="tabular-num">myfile/<?= htmlspecialchars($jobManager->getJobFolderName($job)) ?>/</span>
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const CURRENT_JOB_ID = <?= (int)$job['id'] ?>;
</script>
<script src="assets/js/ui-utils.js?v=<?= filemtime(__DIR__ . '/assets/js/ui-utils.js') ?>"></script>
</body>
</html>
