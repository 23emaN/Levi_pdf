<?php
// ZIP of date_edit/finished/ of the "แก้ไขวันที่ PDF" menu. Kept out of api.php, which answers JSON through ob_gzhandler.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use App\DateEditStore;

function refuse(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!class_exists('ZipArchive')) {
    refuse(500, 'PHP ไม่ได้เปิด extension zip จึงสร้างไฟล์ ZIP ไม่ได้ ใช้ปุ่ม "เปิดโฟลเดอร์" ของ finished แทน');
}

$store = new DateEditStore();
try {
    $dir = $store->finishedDir();
    $files = $store->finishedFiles();
} catch (\Throwable $e) {
    refuse(500, $e->getMessage());
}
if (empty($files)) {
    refuse(404, 'ยังไม่มีไฟล์ที่แก้ไขแล้วใน date_edit/finished/');
}

@set_time_limit(300);
$zipPath = tempnam(sys_get_temp_dir(), 'date_edit_');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    refuse(500, 'สร้างไฟล์ ZIP ไม่ได้');
}
foreach ($files as $relative) {
    $zip->addFile($dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $relative);
}
$zip->close();

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipPath));
header('Content-Disposition: attachment; filename="date_edit_finished_' . date('Ymd_His') . '.zip"');
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);
