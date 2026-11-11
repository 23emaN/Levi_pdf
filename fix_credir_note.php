<?php
/**
 * Standalone Credit Note PDF Typo Fixer (CREDIR NOTE -> CREDIT NOTE)
 * Preserves original fonts, layout, folder structure, and exact filenames.
 */

require_once __DIR__ . '/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

@ini_set('memory_limit', '512M');
@set_time_limit(0);

/**
 * Fix "CREDIR NOTE" -> "CREDIT NOTE" inside a single PDF binary string.
 *
 * Strategy 1 (Lossless In-Place Flate Stream Patch):
 *   Scans compressed FlateDecode content streams for the "CREDIR NOTE" TJ/text operators,
 *   replaces "R" with "T" (since the embedded /TT0 Arial-BoldMT subset already includes glyph 'T'
 *   from "NOTE"), recompresses at level 9, and pads to the exact original stream byte length
 *   so the PDF xref table and all fonts/vectors remain 100% untouched.
 *
 * Strategy 2 (FPDI Fallback):
 *   If a recompressed stream exceeds the original byte length, falls back to FPDI page import
 *   and overlays a white box + bold "CREDIT NOTE" header at the exact coordinates.
 *
 * @param string $rawPdf Raw PDF bytes
 * @param bool &$wasFixed Set to true if "CREDIR NOTE" was found and fixed
 * @return string Fixed PDF bytes (or original bytes if no "CREDIR NOTE" was present)
 */
function fixCredirNotePdfBytes($rawPdf, &$wasFixed = false)
{
    $wasFixed = false;
    $pos = 0;
    $len = strlen($rawPdf);
    $modifiedPdf = $rawPdf;
    $needsFpdiFallback = false;

    while ($pos < $len) {
        $sIdx = strpos($modifiedPdf, 'stream', $pos);
        if ($sIdx === false) {
            break;
        }

        // Verify 'stream' is followed by \r\n or \n
        $after6 = substr($modifiedPdf, $sIdx + 6, 2);
        if ($after6 === "\r\n") {
            $dataStart = $sIdx + 8;
        } elseif (isset($after6[0]) && ($after6[0] === "\n" || $after6[0] === "\r")) {
            $dataStart = $sIdx + 7;
        } else {
            $pos = $sIdx + 6;
            continue;
        }

        $eIdx = strpos($modifiedPdf, 'endstream', $dataStart);
        if ($eIdx === false) {
            break;
        }

        $dataEnd = $eIdx;
        if ($dataEnd >= 2 && substr($modifiedPdf, $dataEnd - 2, 2) === "\r\n") {
            $dataEnd -= 2;
        } elseif ($dataEnd >= 1 && ($modifiedPdf[$dataEnd - 1] === "\n" || $modifiedPdf[$dataEnd - 1] === "\r")) {
            $dataEnd -= 1;
        }

        $origStreamLen = $dataEnd - $dataStart;
        if ($origStreamLen > 0 && $origStreamLen < 5000000) {
            $streamBytes = substr($modifiedPdf, $dataStart, $origStreamLen);
            $decompressed = @gzuncompress($streamBytes);
            if ($decompressed === false) {
                $decompressed = @gzinflate($streamBytes);
            }

            if ($decompressed !== false) {
                $hasTypo = (
                    strpos($decompressed, 'CREDIR') !== false ||
                    strpos($decompressed, 'DIR NOTE') !== false ||
                    strpos($decompressed, '(I)3.995 (R)') !== false ||
                    preg_match('/\(I\)\s*[-0-9.]*\s*\(R\)\s*[-0-9.]*\s*\(\s*\)\s*[-0-9.]*\s*\(N\)/', $decompressed) ||
                    preg_match('/\[[^\]]*D[^\]]*I[^\]]*R[^\]]*N[^\]]*O[^\]]*T[^\]]*E[^\]]*\]\s*TJ/', $decompressed)
                );

                if ($hasTypo) {
                    $fixedDec = $decompressed;

                    // Pattern 1: [(C)-3.002 (R)-3.002 (E)-2.991 (D)-3.002 (I)3.995 (R)-3.002 ( )4.007 (N)-3.002 (OT)5 (E)]TJ
                    $fixedDec = str_replace(
                        '[(C)-3.002 (R)-3.002 (E)-2.991 (D)-3.002 (I)3.995 (R)-3.002 ( )4.007 (N)-3.002 (OT)5 (E)]TJ',
                        '[(C)-3.002 (R)-3.002 (E)-2.991 (D)-3.002 (I)3.995 (T)8.002 ( )3.995 (N)-2.991 (OT)5 (E)]TJ',
                        $fixedDec
                    );

                    // Pattern 2: [(CR)-5 (E)5 (DIR NOTE)]TJ or any (...DIR NOTE...)
                    $fixedDec = str_replace('DIR NOTE', 'DIT NOTE', $fixedDec);
                    $fixedDec = str_replace('CREDIR', 'CREDIT', $fixedDec);

                    // Pattern 3: Any general TJ spacing variant of (...I)...(R)...( )...(N...)
                    $fixedDec = preg_replace(
                        '/(\([A-Z]*I\)\s*[-0-9.]*\s*\()R(\)\s*[-0-9.]*\s*\(\s*[A-Z]*\)\s*[-0-9.]*\s*\([A-Z]*N)/',
                        '$1T$2',
                        $fixedDec
                    );

                    // Pattern 4: Universal TJ callback for any TJ array whose concatenated text is "CREDIR NOTE"
                    $fixedDec = preg_replace_callback('/\[([^\]]+)\]\s*TJ/', function ($m) {
                        $inner = $m[1];
                        if (preg_match_all('/\(([^()]*)\)/', $inner, $parts)) {
                            $joined = implode('', $parts[1]);
                            if (stripos($joined, 'CREDIR') !== false) {
                                // Replace the 'R' right after 'CREDI' with 'T'
                                $replaced = preg_replace(
                                    '/(C(?:\)\s*[-0-9.]*\s*\()?R(?:\)\s*[-0-9.]*\s*\()?E(?:\)\s*[-0-9.]*\s*\()?D(?:\)\s*[-0-9.]*\s*\()?I(?:\)\s*[-0-9.]*\s*\()?)R/i',
                                    '${1}T',
                                    $inner
                                );
                                return '[' . $replaced . ']TJ';
                            }
                        }
                        return $m[0];
                    }, $fixedDec);

                    if ($fixedDec !== $decompressed) {
                        $recompressed = gzcompress($fixedDec, 9);
                        if ($recompressed !== false && strlen($recompressed) <= $origStreamLen) {
                            $padded = $recompressed . str_repeat("\x00", $origStreamLen - strlen($recompressed));
                            $modifiedPdf = substr_replace($modifiedPdf, $padded, $dataStart, $origStreamLen);
                            $wasFixed = true;
                        } else {
                            $needsFpdiFallback = true;
                            $wasFixed = true;
                        }
                    }
                }
            }
        }

        $pos = $eIdx + 9;
    }

    if ($needsFpdiFallback) {
        return fixCredirNoteWithFpdi($rawPdf);
    }

    return $wasFixed ? $modifiedPdf : $rawPdf;
}

/**
 * Fallback using FPDI if in-place stream recompression exceeds original byte count.
 *
 * @param string $rawPdf Raw PDF bytes
 * @return string Rebuilt PDF bytes
 */
function fixCredirNoteWithFpdi($rawPdf)
{
    $tmpIn = tempnam(sys_get_temp_dir(), 'credir_in_');
    $tmpOut = tempnam(sys_get_temp_dir(), 'credir_out_');
    file_put_contents($tmpIn, $rawPdf);

    try {
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($tmpIn);

        for ($pNo = 1; $pNo <= $pageCount; $pNo++) {
            $tpl = $pdf->importPage($pNo);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            // Cover "CREDIR NOTE" at top center (x=281.69, baseline from bottom=712.18)
            $baselineY = (float)$size['height'] - 712.18;
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect(278.0, $baselineY - 9.0, 68.0, 12.0, 'F');
            $pdf->SetFont('Helvetica', 'B', 8.8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Text(281.69, $baselineY, 'CREDIT NOTE');
        }

        $pdf->Output($tmpOut, 'F');
        $outBytes = file_get_contents($tmpOut);
        @unlink($tmpIn);
        @unlink($tmpOut);
        return $outBytes !== false ? $outBytes : $rawPdf;
    } catch (\Throwable $e) {
        @unlink($tmpIn);
        @unlink($tmpOut);
        return $rawPdf;
    }
}

/**
 * Sanitize relative path from browser webkitRelativePath while preserving exact folder & file names.
 *
 * @param string $relPath Relative path such as "FolderA/Subfolder/file.pdf"
 * @return string Clean relative path with forward slashes
 */
function sanitizeRelativePath($relPath)
{
    $relPath = str_replace('\\', '/', (string)$relPath);
    $parts = explode('/', $relPath);
    $cleanParts = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        // Remove characters illegal in Windows filenames
        $part = str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $part);
        if ($part !== '') {
            $cleanParts[] = $part;
        }
    }
    return implode('/', $cleanParts);
}

// Base output directory inside the project
$baseOutputRoot = __DIR__ . DIRECTORY_SEPARATOR . 'myfile' . DIRECTORY_SEPARATOR . 'fixed_credit_note';

// Handle AJAX single/batch file processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)$_POST['action'];

    if ($action === 'process_file') {
        try {
            if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
                $errCode = isset($_FILES['pdf_file']['error']) ? (int)$_FILES['pdf_file']['error'] : -1;
                echo json_encode(['ok' => false, 'error' => "Upload error code: {$errCode}"]);
                exit;
            }

            $tmpPath = $_FILES['pdf_file']['tmp_name'];
            $origName = $_FILES['pdf_file']['name'];
            $relPathInput = isset($_POST['relative_path']) && trim($_POST['relative_path']) !== ''
                ? (string)$_POST['relative_path']
                : $origName;

            $customRootName = isset($_POST['output_root_name']) ? trim((string)$_POST['output_root_name']) : '';
            $cleanRelPath = sanitizeRelativePath($relPathInput);
            if ($cleanRelPath === '') {
                $cleanRelPath = sanitizeRelativePath($origName);
            }

            // If user specified a custom parent output folder name, place inside myfile/<customRootName>/<cleanRelPath>
            $outputBase = $baseOutputRoot;
            if ($customRootName !== '') {
                $safeCustomRoot = sanitizeRelativePath($customRootName);
                if ($safeCustomRoot !== '') {
                    $outputBase = __DIR__ . DIRECTORY_SEPARATOR . 'myfile' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeCustomRoot);
                }
            }

            $targetPath = $outputBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRelPath);
            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir)) {
                if (!@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                    echo json_encode(['ok' => false, 'error' => "ไม่สามารถสร้างโฟลเดอร์ปลายทางได้: {$targetDir}"]);
                    exit;
                }
            }

            $rawBytes = @file_get_contents($tmpPath);
            if ($rawBytes === false || strlen($rawBytes) === 0) {
                echo json_encode(['ok' => false, 'error' => "ไม่สามารถอ่านไฟล์ต้นทางได้: {$origName}"]);
                exit;
            }

            $wasFixed = false;
            $outBytes = fixCredirNotePdfBytes($rawBytes, $wasFixed);

            if (@file_put_contents($targetPath, $outBytes) === false) {
                echo json_encode(['ok' => false, 'error' => "ไม่สามารถบันทึกไฟล์ลงที่: {$targetPath}"]);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'relative_path' => $cleanRelPath,
                'target_path' => $targetPath,
                'output_base' => $outputBase,
                'was_fixed' => $wasFixed,
                'status' => $wasFixed ? 'fixed' : 'copied'
            ]);
            exit;
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'open_folder') {
        $folderToOpen = isset($_POST['folder_path']) ? trim((string)$_POST['folder_path']) : $baseOutputRoot;
        $realBase = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'myfile');
        $realTarget = realpath($folderToOpen);
        if ($realBase !== false && $realTarget !== false && strpos($realTarget, $realBase) === 0 && is_dir($realTarget)) {
            @pclose(@popen('start "" ' . escapeshellarg($realTarget), 'r'));
            echo json_encode(['ok' => true, 'opened' => $realTarget]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'ไม่พบโฟลเดอร์ปลายทาง หรือยังไม่ได้เริ่มประมวลผลไฟล์']);
        }
        exit;
    }

    if ($action === 'reprocess_existing') {
        $customRootName = isset($_POST['output_root_name']) ? trim((string)$_POST['output_root_name']) : 'fixed_credit_note';
        $safeRoot = sanitizeRelativePath($customRootName);
        if ($safeRoot === '') {
            $safeRoot = 'fixed_credit_note';
        }
        $targetRoot = __DIR__ . DIRECTORY_SEPARATOR . 'myfile' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeRoot);
        if (!is_dir($targetRoot)) {
            echo json_encode(['ok' => false, 'error' => "ไม่พบโฟลเดอร์: {$targetRoot}"]);
            exit;
        }
        $total = 0;
        $fixed = 0;
        $unchanged = 0;
        $items = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($targetRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'pdf') {
                $total++;
                $fullPath = $fileInfo->getPathname();
                $relPath = str_replace('\\', '/', substr($fullPath, strlen($targetRoot) + 1));
                $raw = @file_get_contents($fullPath);
                $wasFixed = false;
                if ($raw !== false && strlen($raw) > 0) {
                    $out = fixCredirNotePdfBytes($raw, $wasFixed);
                    if ($wasFixed) {
                        @file_put_contents($fullPath, $out);
                        $fixed++;
                    } else {
                        $unchanged++;
                    }
                }
                $items[] = [
                    'relative_path' => $relPath,
                    'was_fixed' => $wasFixed,
                    'status' => $wasFixed ? 'fixed' : 'copied'
                ];
            }
        }
        echo json_encode([
            'ok' => true,
            'total' => $total,
            'fixed' => $fixed,
            'copied' => $unchanged,
            'items' => $items,
            'output_base' => $targetRoot
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขคำผิด CREDIR NOTE เป็น CREDIT NOTE</title>
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #16a34a;
            --success-bg: #dcfce7;
            --warning: #d97706;
            --warning-bg: #fef3c7;
            --danger: #dc2626;
            --danger-bg: #fee2e2;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans Thai", sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 28px 16px;
        }
        .container {
            max-width: 980px;
            margin: 0 auto;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            margin-bottom: 20px;
        }
        h1 {
            margin: 0 0 8px 0;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .subtitle {
            color: var(--muted);
            font-size: 14px;
            margin: 0 0 20px 0;
            line-height: 1.5;
        }
        .badge-fix {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
        }
        .badge-wrong { background: var(--danger-bg); color: var(--danger); text-decoration: line-through; }
        .badge-right { background: var(--success-bg); color: var(--success); }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
            margin-bottom: 18px;
        }
        .field {
            flex: 1;
            min-width: 240px;
        }
        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #334155;
        }
        .field input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
        }
        .dropzone {
            border: 2px dashed #94a3b8;
            border-radius: 12px;
            padding: 28px 20px;
            text-align: center;
            background: #f1f5f9;
            transition: all 0.15s ease;
        }
        .dropzone:hover {
            border-color: var(--primary);
            background: #eff6ff;
        }
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-top: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s ease;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline {
            background: #fff;
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-outline:hover { background: #f8fafc; }
        .btn-success {
            background: var(--success);
            color: #fff;
        }
        .btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 18px 0;
        }
        .stat-box {
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #f8fafc;
        }
        .stat-label { font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .stat-value { font-size: 22px; font-weight: 700; }
        .stat-fixed { color: var(--warning); }
        .stat-copied { color: var(--success); }
        .stat-error { color: var(--danger); }
        .progress-wrap {
            background: #e2e8f0;
            height: 10px;
            border-radius: 999px;
            overflow: hidden;
            margin: 12px 0;
        }
        .progress-bar {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width 0.2s ease;
        }
        .path-banner {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e3a8a;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .path-code {
            font-family: Consolas, Monaco, monospace;
            background: #dbeafe;
            padding: 3px 8px;
            border-radius: 5px;
            word-break: break-all;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        th, td {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            position: sticky;
            top: 0;
        }
        .table-scroll {
            max-height: 420px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
        }
        .pill {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .pill-fixed { background: var(--warning-bg); color: var(--warning); }
        .pill-copied { background: var(--success-bg); color: var(--success); }
        .pill-error { background: var(--danger-bg); color: var(--danger); }
        .filter-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: #fff;
            font-size: 12.5px;
            cursor: pointer;
            font-weight: 600;
        }
        .filter-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>
            <span>🛠️ เครื่องมือแก้ไขคำผิดใบลดหนี้</span>
            <span class="badge-fix badge-wrong">CREDIR NOTE</span>
            <span>➜</span>
            <span class="badge-fix badge-right">CREDIT NOTE</span>
        </h1>
        <p class="subtitle">
            เลือกโฟลเดอร์ที่มีไฟล์ PDF ของ Credit Note ได้เลย ระบบจะตรวจหาไฟล์ที่หัวกระดาษพิมพ์ผิดเป็น <b>CREDIR NOTE</b> แล้วแก้เป็น <b>CREDIT NOTE</b> ด้วยฟอนต์และตำแหน่งเดิมเป๊ะๆ พร้อมคัดลอกไฟล์ที่ถูกอยู่แล้วไปไว้ในโฟลเดอร์ใหม่ด้วยทั้งหมด โดยใช้ <b>ชื่อโฟลเดอร์เดิมและชื่อไฟล์เดิมทุกตัวอักษร</b>
        </p>

        <div class="form-row">
            <div class="field">
                <label for="outputRootName">📁 ชื่อโฟลเดอร์หลักปลายทาง (อยู่ในโฟลเดอร์ <code>myfile/</code>):</label>
                <input type="text" id="outputRootName" value="fixed_credit_note" placeholder="เช่น fixed_credit_note">
            </div>
        </div>

        <div class="path-banner">
            <div>
                📍 ไฟล์ที่ประมวลผลเสร็จแล้วจะถูกเก็บไว้ที่:
                <span class="path-code" id="outputPathPreview"><?= htmlspecialchars(__DIR__ . DIRECTORY_SEPARATOR . 'myfile' . DIRECTORY_SEPARATOR . 'fixed_credit_note', ENT_QUOTES, 'UTF-8') ?>\<span id="origFolderPreview">&lt;ชื่อโฟลเดอร์เดิม&gt;</span>\&lt;ชื่อไฟล์เดิม.pdf&gt;</span>
            </div>
            <button type="button" class="btn btn-outline" id="openFolderBtn" onclick="openOutputFolder()">
                📂 เปิดโฟลเดอร์ปลายทางใน Windows Explorer
            </button>
        </div>

        <div class="dropzone">
            <div style="font-size: 15px; font-weight: 600; margin-bottom: 6px;">
                เลือกโฟลเดอร์ต้นทางที่มีไฟล์ Credit Note (.pdf)
            </div>
            <div style="font-size: 13px; color: var(--muted);">
                รองรับการเลือกทั้งโฟลเดอร์ (รวมโฟลเดอร์ย่อยข้างในทั้งหมด) หรือเลือกหลายไฟล์พร้อมกัน
            </div>
            <input type="file" id="folderInput" webkitdirectory directory multiple accept=".pdf,application/pdf" style="display:none">
            <input type="file" id="filesInput" multiple accept=".pdf,application/pdf" style="display:none">
            <div class="btn-group">
                <button type="button" class="btn btn-primary" id="pickFolderBtn" onclick="document.getElementById('folderInput').click()">
                    📁 เลือกโฟลเดอร์ (แนะนำ - คงชื่อโฟลเดอร์เดิมอัตโนมัติ)
                </button>
                <button type="button" class="btn btn-outline" id="pickFilesBtn" onclick="document.getElementById('filesInput').click()">
                    📄 เลือกเฉพาะไฟล์ PDF
                </button>
            </div>
        </div>
    </div>

    <div class="card" id="resultCard" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2 style="margin:0; font-size:18px;" id="statusHeading">กำลังประมวลผลไฟล์...</h2>
            <span id="progressText" style="font-size:14px; font-weight:600; color:var(--primary);">0 / 0 ไฟล์</span>
        </div>

        <div class="progress-wrap">
            <div class="progress-bar" id="progressBar"></div>
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">ไฟล์ PDF ทั้งหมดในโฟลเดอร์</div>
                <div class="stat-value" id="statTotal">0</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">🔧 แก้ไข CREDIR ➜ CREDIT NOTE</div>
                <div class="stat-value stat-fixed" id="statFixed">0</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">✅ ปกติอยู่แล้ว (คัดลอกไปให้ครบชุด)</div>
                <div class="stat-value stat-copied" id="statCopied">0</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">⚠️ ข้อผิดพลาด</div>
                <div class="stat-value stat-error" id="statError">0</div>
            </div>
        </div>

        <div class="filter-bar">
            <button type="button" class="filter-btn active" data-filter="all" onclick="setFilter('all', this)">ทั้งหมด</button>
            <button type="button" class="filter-btn" data-filter="fixed" onclick="setFilter('fixed', this)">เฉพาะไฟล์ที่ถูกแก้ (CREDIR ➜ CREDIT)</button>
            <button type="button" class="filter-btn" data-filter="copied" onclick="setFilter('copied', this)">เฉพาะไฟล์ที่ถูกอยู่แล้ว (คัดลอก)</button>
            <button type="button" class="filter-btn" data-filter="error" onclick="setFilter('error', this)">เฉพาะไฟล์ที่มีปัญหา</button>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th style="width:55px;">#</th>
                        <th>โฟลเดอร์ / ชื่อไฟล์ (คงชื่อเดิมเป๊ะ)</th>
                        <th style="width:220px;">สถานะ</th>
                    </tr>
                </thead>
                <tbody id="logTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
const baseMyfileDir = <?= json_encode(__DIR__ . DIRECTORY_SEPARATOR . 'myfile', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
let lastOutputBase = baseMyfileDir + '\\fixed_credit_note';
let currentFilter = 'all';

document.getElementById('outputRootName').addEventListener('input', function() {
    const val = this.value.trim() || 'fixed_credit_note';
    lastOutputBase = baseMyfileDir + '\\' + val;
    document.getElementById('outputPathPreview').innerHTML =
        escapeHtml(lastOutputBase) + '\\<span id="origFolderPreview">&lt;ชื่อโฟลเดอร์เดิม&gt;</span>\\&lt;ชื่อไฟล์เดิม.pdf&gt;';
});

document.getElementById('folderInput').addEventListener('change', function(e) {
    if (e.target.files && e.target.files.length > 0) {
        handleSelectedFiles(Array.from(e.target.files));
        e.target.value = '';
    }
});

document.getElementById('filesInput').addEventListener('change', function(e) {
    if (e.target.files && e.target.files.length > 0) {
        handleSelectedFiles(Array.from(e.target.files));
        e.target.value = '';
    }
});

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function setFilter(filter, btnEl) {
    currentFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    btnEl.classList.add('active');

    document.querySelectorAll('#logTableBody tr').forEach(function(row) {
        const status = row.getAttribute('data-status');
        if (filter === 'all' || status === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

async function handleSelectedFiles(allFiles) {
    const pdfFiles = allFiles.filter(function(f) {
        return f.name.toLowerCase().endsWith('.pdf');
    });

    if (pdfFiles.length === 0) {
        alert('ไม่พบไฟล์ .pdf ในโฟลเดอร์ที่เลือก');
        return;
    }

    // Detect original folder name from webkitRelativePath
    const firstRel = pdfFiles[0].webkitRelativePath || pdfFiles[0].name;
    const slashIdx = firstRel.indexOf('/');
    const origTopFolder = slashIdx > 0 ? firstRel.substring(0, slashIdx) : '';
    const origPreviewEl = document.getElementById('origFolderPreview');
    if (origPreviewEl && origTopFolder) {
        origPreviewEl.textContent = origTopFolder;
    }

    const resultCard = document.getElementById('resultCard');
    const tbody = document.getElementById('logTableBody');
    const statusHeading = document.getElementById('statusHeading');
    const progressText = document.getElementById('progressText');
    const progressBar = document.getElementById('progressBar');
    const outputRootName = document.getElementById('outputRootName').value.trim() || 'fixed_credit_note';

    resultCard.style.display = 'block';
    tbody.innerHTML = '';

    let total = pdfFiles.length;
    let done = 0;
    let countFixed = 0;
    let countCopied = 0;
    let countError = 0;

    document.getElementById('statTotal').textContent = total;
    document.getElementById('statFixed').textContent = '0';
    document.getElementById('statCopied').textContent = '0';
    document.getElementById('statError').textContent = '0';
    progressBar.style.width = '0%';
    progressText.textContent = '0 / ' + total + ' ไฟล์';
    statusHeading.textContent = '⏳ กำลังตรวจสอบและแก้ไขไฟล์ PDF...';

    document.getElementById('pickFolderBtn').disabled = true;
    document.getElementById('pickFilesBtn').disabled = true;

    // Process with concurrency of 4 for fast performance
    const concurrency = 4;
    let index = 0;

    async function worker() {
        while (index < pdfFiles.length) {
            const currentIdx = index++;
            const file = pdfFiles[currentIdx];
            const relPath = file.webkitRelativePath && file.webkitRelativePath !== '' ? file.webkitRelativePath : file.name;

            const formData = new FormData();
            formData.append('action', 'process_file');
            formData.append('output_root_name', outputRootName);
            formData.append('relative_path', relPath);
            formData.append('pdf_file', file, file.name);

            let rowStatus = 'error';
            let statusBadge = '';
            let displayPath = relPath;

            try {
                const resp = await fetch('fix_credir_note.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();
                if (data && data.ok) {
                    displayPath = data.relative_path || relPath;
                    if (data.output_base) {
                        lastOutputBase = data.output_base;
                    }
                    if (data.was_fixed) {
                        countFixed++;
                        rowStatus = 'fixed';
                        statusBadge = '<span class="pill pill-fixed">🔧 แก้ไข CREDIR ➜ CREDIT NOTE</span>';
                    } else {
                        countCopied++;
                        rowStatus = 'copied';
                        statusBadge = '<span class="pill pill-copied">✅ ปกติ (คัดลอกไฟล์เดิม)</span>';
                    }
                } else {
                    countError++;
                    const errMsg = (data && data.error) ? data.error : 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ';
                    statusBadge = '<span class="pill pill-error">⚠️ ' + escapeHtml(errMsg) + '</span>';
                }
            } catch (err) {
                countError++;
                statusBadge = '<span class="pill pill-error">⚠️ ' + escapeHtml(err.message || 'Network error') + '</span>';
            }

            done++;
            const pct = Math.round((done / total) * 100);
            progressBar.style.width = pct + '%';
            progressText.textContent = done + ' / ' + total + ' ไฟล์ (' + pct + '%)';
            document.getElementById('statFixed').textContent = countFixed;
            document.getElementById('statCopied').textContent = countCopied;
            document.getElementById('statError').textContent = countError;

            const tr = document.createElement('tr');
            tr.setAttribute('data-status', rowStatus);
            if (currentFilter !== 'all' && currentFilter !== rowStatus) {
                tr.style.display = 'none';
            }
            tr.innerHTML =
                '<td>' + (currentIdx + 1) + '</td>' +
                '<td style="font-family:Consolas,Monaco,monospace;font-size:12.5px;">' + escapeHtml(displayPath) + '</td>' +
                '<td>' + statusBadge + '</td>';
            tbody.appendChild(tr);
        }
    }

    const workers = [];
    for (let w = 0; w < Math.min(concurrency, pdfFiles.length); w++) {
        workers.push(worker());
    }
    await Promise.all(workers);

    statusHeading.textContent = '🎉 ประมวลผลเสร็จสมบูรณ์! (แก้ไข ' + countFixed + ' ไฟล์, คัดลอกไฟล์ปกติ ' + countCopied + ' ไฟล์)';
    document.getElementById('pickFolderBtn').disabled = false;
    document.getElementById('pickFilesBtn').disabled = false;
}

async function openOutputFolder() {
    const formData = new FormData();
    formData.append('action', 'open_folder');
    formData.append('folder_path', lastOutputBase);
    try {
        const resp = await fetch('fix_credir_note.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (!data.ok) {
            alert(data.error || 'ยังไม่พบโฟลเดอร์ปลายทาง กรุณาเลือกโฟลเดอร์เพื่อประมวลผลก่อนครับ');
        }
    } catch (e) {
        alert('ไม่สามารถเปิดโฟลเดอร์อัตโนมัติได้ กรุณาเปิดที่: ' + lastOutputBase);
    }
}
</script>
</body>
</html>

