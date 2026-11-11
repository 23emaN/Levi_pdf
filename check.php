<?php
/**
 * ตรวจเครื่องก่อนใช้งาน (AppServ / XAMPP): เวอร์ชัน PHP ส่วนขยาย สิทธิ์เขียนโฟลเดอร์ และค่าใน php.ini
 * เปิดที่ http://localhost/{ชื่อโฟลเดอร์}/check.php  หน้านี้ไม่แก้ไขอะไรนอกจากสร้างโฟลเดอร์ data, myfile, date_edit ถ้ายังไม่มี
 * ไม่โหลด vendor/ จึงเปิดได้แม้ PHP หรือส่วนขยายยังไม่ครบ
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');

function check_bytes($value)
{
    $value = trim((string)$value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    switch ($unit) {
        case 'g': return (int)($number * 1024 * 1024 * 1024);
        case 'm': return (int)($number * 1024 * 1024);
        case 'k': return (int)($number * 1024);
    }
    return (int)$number;
}

$root = __DIR__;
$rows = array();
$add = function ($group, $name, $state, $detail) use (&$rows) {
    $rows[] = array($group, $name, $state, $detail);
};

// PHP
$add('PHP', 'เวอร์ชัน PHP', version_compare(PHP_VERSION, '7.3.0', '>=') ? 'ok' : 'fail',
    PHP_VERSION . ' (ต้องการ 7.3 ขึ้นไป AppServ 9.3 มี PHP 7.3)');
$add('PHP', 'ระบบปฏิบัติการ', PHP_OS_FAMILY === 'Windows' ? 'ok' : 'warn',
    PHP_OS_FAMILY . (PHP_OS_FAMILY === 'Windows' ? '' : ' (ปุ่มเปิดโฟลเดอร์ใน Explorer ใช้ได้เฉพาะ Windows)'));
$add('PHP', 'ไฟล์ php.ini ที่ใช้อยู่', 'info', php_ini_loaded_file() ?: '(ไม่มี)');
$add('PHP', 'Server API', 'info', PHP_SAPI . ' / ' . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '-'));

// Extensions
$extensions = array(
    'pdo_sqlite' => array('fail', 'ฐานข้อมูล ใน php.ini เปิดบรรทัด extension=pdo_sqlite (ลบ ; ข้างหน้า)'),
    'zlib'       => array('fail', 'อ่านและเขียน PDF'),
    'iconv'      => array('fail', 'อ่าน PDF'),
    'fileinfo'   => array('fail', 'ตรวจชนิดไฟล์ ใน php.ini เปิดบรรทัด extension=fileinfo'),
    'mbstring'   => array('warn', 'ถ้าไม่มี ใช้ตัวแทนใน vendor/ ได้แต่ช้ากว่า ใน php.ini เปิดบรรทัด extension=mbstring'),
    'json'       => array('fail', 'คำตอบของ api.php'),
);
foreach ($extensions as $name => $info) {
    $loaded = extension_loaded($name);
    $add('ส่วนขยาย PHP', $name, $loaded ? 'ok' : $info[0], $loaded ? 'เปิดอยู่' : 'ไม่ได้เปิด: ' . $info[1]);
}
if (extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO('sqlite::memory:');
        $version = $pdo->query('select sqlite_version()')->fetchColumn();
        $add('ส่วนขยาย PHP', 'SQLite', 'ok', 'เวอร์ชัน ' . $version);
    } catch (Exception $e) {
        $add('ส่วนขยาย PHP', 'SQLite', 'fail', $e->getMessage());
    }
}
$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$procOk = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
$add('ส่วนขยาย PHP', 'proc_open', $procOk ? 'ok' : 'warn',
    $procOk ? 'ใช้ได้ (ปุ่มเปิดโฟลเดอร์ใน Explorer)' : 'ถูกปิด: ปุ่มเปิดโฟลเดอร์จะแสดงพาธให้คัดลอกไปเปิดเองแทน');

// Folders
foreach (array('data', 'myfile', 'date_edit' . DIRECTORY_SEPARATOR . 'upload here', 'date_edit' . DIRECTORY_SEPARATOR . 'finished') as $folder) {
    $path = $root . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    $probe = $path . DIRECTORY_SEPARATOR . '.write_test_' . uniqid();
    $writable = is_dir($path) && @file_put_contents($probe, 'ok') !== false;
    if ($writable) {
        @unlink($probe);
    }
    $add('โฟลเดอร์', $folder, $writable ? 'ok' : 'fail',
        $writable ? 'เขียนได้' : 'เขียนไม่ได้: ให้สิทธิ์ Modify กับโฟลเดอร์ ' . $path . ' หรือย้ายโปรแกรมออกจาก Program Files');
}
$vendorOk = is_file($root . '/vendor/autoload.php');
$add('โฟลเดอร์', 'vendor/', $vendorOk ? 'ok' : 'fail', $vendorOk ? 'มีไลบรารีครบ' : 'ไม่มี vendor/autoload.php แตกไฟล์ zip ใหม่ให้ครบ');
$thaiProbe = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.ทดสอบชื่อไทย_' . uniqid();
$thaiOk = @mkdir($thaiProbe);
if ($thaiOk) {
    @rmdir($thaiProbe);
}
$add('โฟลเดอร์', 'ชื่อโฟลเดอร์ภาษาไทย', $thaiOk ? 'ok' : 'warn',
    $thaiOk ? 'สร้างได้ (ชื่อ Job และ "print ครั้งที่ N")' : 'สร้างไม่ได้: ตรวจว่า PHP เป็น 7.1 ขึ้นไป');

// php.ini limits
$upload = ini_get('upload_max_filesize');
$post = ini_get('post_max_size');
$uploadOk = check_bytes($upload) >= 50 * 1024 * 1024 && check_bytes($post) >= check_bytes($upload);
$add('ค่าใน php.ini', 'upload_max_filesize / post_max_size', $uploadOk ? 'ok' : 'warn',
    $upload . ' / ' . $post . ($uploadOk ? '' : ' (ไฟล์ ASN/VBRP ใหญ่จะนำเข้าไม่ได้ ควรตั้งอย่างน้อย 200M / 210M)'));
$memory = ini_get('memory_limit');
$memoryOk = check_bytes($memory) >= 512 * 1024 * 1024;
$add('ค่าใน php.ini', 'memory_limit', $memoryOk ? 'ok' : 'warn', $memory . ($memoryOk ? '' : ' (ควรตั้ง 1024M)'));
$time = (int)ini_get('max_execution_time');
$timeOk = $time === 0 || $time >= 300;
$add('ค่าใน php.ini', 'max_execution_time', $timeOk ? 'ok' : 'warn', $time . ' วินาที' . ($timeOk ? '' : ' (ควรตั้ง 600)'));

// Is the database reachable from the web? Only checks the effect of .htaccess, never prints the data
$protection = 'info';
$protectionText = 'ตรวจไม่ได้จากหน้านี้ ลองเปิด data/app.sqlite ในเบราว์เซอร์ ต้องได้ 403 Forbidden';
if (isset($_SERVER['HTTP_HOST']) && function_exists('stream_context_create') && is_file($root . '/data/app.sqlite')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . '/data/app.sqlite';
    $context = stream_context_create(array('http' => array('method' => 'HEAD', 'timeout' => 3, 'ignore_errors' => true)));
    $headers = @get_headers($url, 0, $context);
    if (is_array($headers) && isset($headers[0])) {
        $blocked = !preg_match('/\s200\s/', $headers[0]);
        $protection = $blocked ? 'ok' : 'warn';
        $protectionText = $blocked
            ? 'เปิดผ่านเว็บไม่ได้ (' . $headers[0] . ')'
            : 'เปิดผ่านเว็บได้! ใน httpd.conf ของ AppServ ตั้ง AllowOverride All ให้โฟลเดอร์ www แล้ว Restart Apache';
    }
}
$add('ความปลอดภัย', 'data/app.sqlite ผ่านเว็บ', $protection, $protectionText);

$labels = array('ok' => 'ผ่าน', 'warn' => 'ควรแก้', 'fail' => 'ต้องแก้', 'info' => 'ข้อมูล');
$fails = 0;
$warns = 0;
foreach ($rows as $row) {
    if ($row[2] === 'fail') { $fails++; }
    if ($row[2] === 'warn') { $warns++; }
}
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ตรวจเครื่องก่อนใช้งาน</title>
<style>
    body { font-family: Tahoma, "Segoe UI", sans-serif; background: #f5f6f8; color: #222; margin: 0; padding: 24px; }
    .box { max-width: 980px; margin: 0 auto; background: #fff; border-radius: 10px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    h1 { font-size: 22px; margin: 0 0 6px; }
    .summary { padding: 12px 16px; border-radius: 8px; margin: 16px 0; font-weight: bold; }
    .summary.ok { background: #e6f6ea; color: #1b6e32; }
    .summary.warn { background: #fff6dd; color: #8a6100; }
    .summary.fail { background: #fde8e8; color: #a11d1d; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eceef1; vertical-align: top; }
    th { background: #fafbfc; }
    td.group { color: #777; white-space: nowrap; }
    .tag { display: inline-block; min-width: 56px; text-align: center; border-radius: 12px; padding: 2px 8px; font-size: 12px; font-weight: bold; }
    .tag.ok { background: #e6f6ea; color: #1b6e32; }
    .tag.warn { background: #fff6dd; color: #8a6100; }
    .tag.fail { background: #fde8e8; color: #a11d1d; }
    .tag.info { background: #eef1f5; color: #555; }
    td.detail { word-break: break-all; }
    a.btn { display: inline-block; margin-top: 18px; padding: 8px 16px; background: #1f6feb; color: #fff; border-radius: 6px; text-decoration: none; }
</style>
</head>
<body>
<div class="box">
    <h1>ตรวจเครื่องก่อนใช้งาน</h1>
    <div>โฟลเดอร์โปรแกรม: <?= htmlspecialchars($root) ?></div>
    <?php if ($fails > 0): ?>
        <div class="summary fail">มี <?= $fails ?> ข้อที่ต้องแก้ก่อนใช้งาน (แก้ php.ini แล้วต้อง Restart Apache)</div>
    <?php elseif ($warns > 0): ?>
        <div class="summary warn">ใช้งานได้ แต่มี <?= $warns ?> ข้อที่ควรแก้</div>
    <?php else: ?>
        <div class="summary ok">พร้อมใช้งาน</div>
    <?php endif; ?>
    <table>
        <tr><th>หมวด</th><th>รายการ</th><th>ผล</th><th>รายละเอียด</th></tr>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="group"><?= htmlspecialchars($row[0]) ?></td>
                <td><?= htmlspecialchars($row[1]) ?></td>
                <td><span class="tag <?= $row[2] ?>"><?= $labels[$row[2]] ?></span></td>
                <td class="detail"><?= htmlspecialchars($row[3]) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <a class="btn" href="index.php">ไปหน้าโปรแกรม</a>
</div>
</body>
</html>
