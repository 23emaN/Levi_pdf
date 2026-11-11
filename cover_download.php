<?php
// One cover sheet PDF of a Job, shown in the browser to print (or saved with download=1). Kept out of api.php, which
// answers JSON through ob_gzhandler.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\CoverSheet;
use App\JobManager;

function refuse(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$jobId = intval($_GET['job_id'] ?? 0);
$file = (string)($_GET['file'] ?? '');
try {
    $jobs = new JobManager();
    if (!$jobs->getJob($jobId)) {
        refuse(404, 'ไม่พบ Job นี้');
    }
    $path = (new CoverSheet($jobs))->filePath($jobId, $file);
} catch (\Throwable $e) {
    refuse(500, $e->getMessage());
}
if ($path === null) {
    refuse(404, 'ไม่พบไฟล์ใบปะหน้านี้');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
$disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: $disposition; filename=\"cover_sheet.pdf\"; filename*=UTF-8''" . rawurlencode($file));
header('Cache-Control: no-store');
readfile($path);
