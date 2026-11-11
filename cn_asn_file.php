<?php
// One ASN workbook kept by "ใส่ยอด PDF ลง ASN" (tab 4 of credit_note.php), as it was uploaded. The page patches it with
// the PDF totals before the user saves it (assets/js/credit-note/asn-fill.js). Kept out of api.php, which answers JSON
// through ob_gzhandler.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\JobManager;

function refuse(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$jobId = intval($_GET['job_id'] ?? 0);
$fileId = intval($_GET['file_id'] ?? 0);
try {
    $jobs = new JobManager();
    if (!$jobs->getJob($jobId)) {
        refuse(404, 'ไม่พบ Job นี้');
    }
    $file = $jobs->creditNoteFill()->filePath($jobId, $fileId);
} catch (\Throwable $e) {
    refuse(500, $e->getMessage());
}
if ($file === null) {
    refuse(404, 'ไม่พบไฟล์ ASN นี้ในโฟลเดอร์ CREDIT NOTE/ASN นำเข้าไฟล์ใหม่อีกครั้ง');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Length: ' . filesize($file['path']));
header("Content-Disposition: attachment; filename=\"asn.xlsx\"; filename*=UTF-8''" . rawurlencode($file['file_name']));
header('Cache-Control: no-store');
readfile($file['path']);
