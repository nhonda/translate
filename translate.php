<?php
session_start();
$sid = session_id();
session_write_close();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';

use Dotenv\Dotenv;

// Load .env if present
$dotenv = Dotenv::createImmutable(__DIR__);
if (file_exists(__DIR__ . '/.env')) {
    $dotenv->load();
}

// Helper: get first non-empty env value
function env_non_empty(string $key): string {
    $candidates = [];
    if (array_key_exists($key, $_ENV))    { $candidates[] = $_ENV[$key]; }
    if (array_key_exists($key, $_SERVER)) { $candidates[] = $_SERVER[$key]; }
    $getenv = getenv($key);
    if ($getenv !== false) { $candidates[] = $getenv; }
    foreach ($candidates as $v) {
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '') return $t;
        }
    }
    return '';
}

// Prefer first non-empty among supported keys
$apiKey  = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
$debug   = filter_var(env_non_empty('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN);

// Debug helpers
function debug_log(string $msg): void {
    global $debug;
    if ($debug) {
        error_log($msg);
    }
}
function redact_url(string $url): string {
    // Mask auth_key query parameter if present
    return preg_replace('/(auth_key=)[^&\s]+/i', '$1[REDACTED]', $url);
}

// 軽量な文字数カウント（履歴の実文字数補完用）
function count_chars_light(string $path): int|false {
    if (!is_file($path)) return false;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $text = '';
    if ($ext === 'txt') {
        $text = @file_get_contents($path) ?: '';
    } elseif ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    } elseif ($ext === 'xlsx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $bufs = [];
            // sharedStrings.xml
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            if ($xml !== false) {
                $bufs[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            // worksheets inlineStr
            for ($i = 1; $i <= 200; $i++) {
                $sheetName = sprintf('xl/worksheets/sheet%d.xml', $i);
                $sx = $zip->getFromName($sheetName);
                if ($sx === false) {
                    if ($i === 1) {}
                    break;
                }
                if (preg_match_all('/<c[^>]*t="inlineStr"[^>]*>.*?<is>(.*?)<\/is>.*?<\/c>/si', $sx, $m)) {
                    foreach ($m[1] as $frag) {
                        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/si', $frag, $mt)) {
                            foreach ($mt[1] as $t) {
                                $bufs[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
                            }
                        } else {
                            $bufs[] = html_entity_decode(strip_tags($frag), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                }
            }
            $zip->close();
            $text = trim(implode("\n", array_filter($bufs)));
        }
    } elseif ($ext === 'pptx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $bufs = [];
            for ($i = 1; $i <= 200; $i++) {
                $name = sprintf('ppt/slides/slide%d.xml', $i);
                $xml = $zip->getFromName($name);
                if ($xml === false) break;
                $bufs[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $text = trim(implode("\n", $bufs));
            $zip->close();
        }
    } else {
        // PDFやその他はここでは未対応（アップロード時の履歴を優先）
        return false;
    }
    if (!is_string($text)) return false;
    $text = trim($text);
    if ($text === '') return false;
    return mb_strlen($text, 'UTF-8');
}
$price   = (float)($_ENV['DEEPL_PRICE_PER_MILLION'] ?? getenv('DEEPL_PRICE_PER_MILLION') ?? 25);
$priceCcy = $_ENV['DEEPL_PRICE_CCY'] ?? getenv('DEEPL_PRICE_CCY') ?? 'USD';
$missing = [];
if ($apiKey === '') {
    $missing[] = 'DEEPL_API_KEY/DEEPL_AUTH_KEY';
}
if ($apiBase === '') {
    $missing[] = 'DEEPL_API_BASE';
}
if ($missing) {
    error_log('[DeepL] missing env vars: ' . implode(', ', $missing));
    http_response_code(500);
    echo 'DeepL API設定が不足しています';
    exit;
}

// Debug: 設定確認（キーは末尾のみ表示）
$maskedTail = $apiKey !== '' ? substr($apiKey, -4) : '';
debug_log(sprintf('[DeepL] using base=%s key_len=%d key_tail=%s', $apiBase, strlen($apiKey), $maskedTail));

$filename = $_POST['filename'] ?? '';
$outputFormat = trim($_POST['output_format'] ?? '');
$targetLangIn = strtoupper(trim($_POST['target_lang'] ?? ''));
// Normalize and validate target language
if ($targetLangIn === 'EN') { $targetLangIn = 'EN-US'; }
if (!in_array($targetLangIn, ['JA','EN-US','EN-GB'], true)) {
    $targetLangIn = 'JA';
}
$targetLang = $targetLangIn;
$userTarget = $targetLang; // ユーザー指定の翻訳先（固定）
$suffix = ($userTarget === 'JA') ? 'jp' : 'en';
$outputFormat = in_array($outputFormat, ['pdf', 'docx', 'txt'], true) ? $outputFormat : '';
$src = __DIR__ . '/uploads/' . basename($filename);
if ($filename === '' || !is_file($src)) {
    http_response_code(400);
    echo 'ファイルが見つかりません';
    exit;
}
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowed = ['pdf','docx','pptx','xlsx','doc','txt'];
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo '非対応形式';
    exit;
}

$progressFile = sys_get_temp_dir() . '/progress_' . $sid . '.json';
$updateProgress = function(int $percent, string $message) use ($progressFile) {
    file_put_contents($progressFile, json_encode(['percent'=>$percent, 'message'=>$message]));
};
register_shutdown_function(function() use ($progressFile) {
    if (is_file($progressFile)) {
        unlink($progressFile);
    }
});

$billed = 0;
$estCost = 0;
$fallbackApplied = false;
$outPath = '';
$outName = '';

if ($ext === 'txt') {
    $updateProgress(10, 'テキストを送信中');
    $text = file_get_contents($src);
    if ($text === false) {
        http_response_code(500);
        echo 'テキストの読み込みに失敗しました';
        exit;
    }
    $translateUrl = $apiBase . '/translate' . '?auth_key=' . rawurlencode($apiKey);
    debug_log('[DeepL] POST ' . redact_url($translateUrl) . ' (text)');
    $doTextRequest = function(string $tgt, string $srcHint = '') use ($translateUrl, $apiKey, $text) {
        $ch = curl_init($translateUrl);
        $fields = ['auth_key' => $apiKey, 'text' => $text, 'target_lang' => $tgt];
        if ($srcHint !== '') { $fields['source_lang'] = $srcHint; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ]);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrOut = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$res, $code, $hdrOut, $err];
    };
    $sourceHint = '';
    [$res, $code, $hdrOut, $err] = $doTextRequest($userTarget, $sourceHint);
    if ($hdrOut) {
        $redacted = preg_replace('/(Authorization:\s*DeepL-Auth-Key\s+)[^\r\n]+/i', '$1[REDACTED]', $hdrOut);
        $redacted = preg_replace('/(auth_key=)[^\s&]+/i', '$1[REDACTED]', $redacted);
        debug_log('[DeepL] request-headers: ' . trim($redacted));
    }
    if ($res === false || $code >= 400) {
        $detail = $res ? (json_decode($res, true)['message'] ?? $res) : $err;
        // 自動リトライ（原文=翻訳先）: ターゲットは固定し、source_lang を指定して再試行
        if (is_string($detail) && stripos($detail, 'Source and target language are equal') !== false && $sourceHint === '') {
            $updateProgress(12, '原文言語を指定して再試行中');
            $sourceHint = ($userTarget === 'JA') ? 'EN' : 'JA';
            [$res, $code, $hdrOut, $err] = $doTextRequest($userTarget, $sourceHint);
        }
        if ($res === false || $code >= 400) {
            $detail = $res ? (json_decode($res, true)['message'] ?? $res) : $err;
            error_log("[DeepL] status=$code message=$detail");
            http_response_code($code ?: 500);
            echo '翻訳に失敗しました';
            exit;
        }
    }
    $data = json_decode($res, true);
    $translated = $data['translations'][0]['text'] ?? '';
    if ($translated === '') {
        http_response_code(500);
        echo '翻訳結果の解析に失敗しました';
        exit;
    }
    $outDir = __DIR__ . '/downloads';
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
        error_log('Failed to create downloads directory: ' . $outDir);
        http_response_code(500);
        echo '出力ディレクトリの作成に失敗しました';
        exit;
    }
    // Decide output format for TXT uploads (txt/pdf/docx)
    $selected = $outputFormat !== '' ? $outputFormat : 'txt';
    $baseName = preg_replace('/_(jp|en)$/i', '', pathinfo($filename, PATHINFO_FILENAME));
    if ($selected === 'pdf') {
        $outName = $baseName . '_' . $suffix . '.pdf';
        $outPath = $outDir . '/' . $outName;
        try {
            $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir(), 'mode' => 'utf-8']);
            $html = nl2br(htmlspecialchars($translated, ENT_QUOTES, 'UTF-8'));
            $mpdf->WriteHTML($html);
            $mpdf->Output($outPath, 'F');
        } catch (\Throwable $e) {
            error_log('Failed to generate PDF: ' . $e->getMessage());
            http_response_code(500);
            echo 'PDF生成に失敗しました';
            exit;
        }
    } elseif ($selected === 'docx') {
        $outName = $baseName . '_' . $suffix . '.docx';
        $outPath = $outDir . '/' . $outName;
        try {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            foreach (preg_split("/\r?\n/", $translated) as $line) {
                $section->addText($line);
            }
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($outPath);
        } catch (\Throwable $e) {
            error_log('Failed to generate DOCX: ' . $e->getMessage());
            http_response_code(500);
            echo 'DOCX生成に失敗しました';
            exit;
        }
    } else { // txt
        $outName = $baseName . '_' . $suffix . '.txt';
        $outPath = $outDir . '/' . $outName;
        if (file_put_contents($outPath, $translated) === false) {
            error_log('Failed to save translated file: ' . $outPath);
            http_response_code(500);
            echo '翻訳結果の保存に失敗しました';
            exit;
        }
    }
    $billed = mb_strlen($text);
    $estCost = $billed / 1000000 * $price;
    $updateProgress(100, '完了');
} else {
    $updateProgress(10, 'ドキュメントを送信中');
    $sourceHint = '';
    $attempt = 0;
    while (true) {
        // create document
        $docCreateUrl = $apiBase . '/document' . '?auth_key=' . rawurlencode($apiKey);
        debug_log('[DeepL] POST ' . redact_url($docCreateUrl) . ' (document)');
        $ch = curl_init($docCreateUrl);
        $postFields = [
            'file' => new CURLFile($src),
            'target_lang' => $userTarget,
            'auth_key' => $apiKey,
        ];
        // Decide DeepL output format: allow 'pdf' or 'docx'. For 'txt', request 'docx' then flatten to text after.
        $deeplFormat = '';
        if (in_array($outputFormat, ['pdf','docx'], true)) {
            $deeplFormat = $outputFormat;
        } elseif ($outputFormat === 'txt') {
            $deeplFormat = 'docx';
        }
        if ($deeplFormat !== '') {
            $postFields['output_format'] = $deeplFormat;
        }
        if ($sourceHint !== '') {
            $postFields['source_lang'] = $sourceHint;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ]);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrOut = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($hdrOut) {
            $redacted = preg_replace('/(Authorization:\s*DeepL-Auth-Key\s+)[^\r\n]+/i', '$1[REDACTED]', $hdrOut);
            $redacted = preg_replace('/(auth_key=)[^\s&]+/i', '$1[REDACTED]', $redacted);
            debug_log('[DeepL] request-headers: ' . trim($redacted));
        }
        if ($res === false || $code >= 400) {
            $detail = $res ? (json_decode($res, true)['message'] ?? $res) : $err;
            if ($attempt === 0 && is_string($detail) && stripos($detail, 'Source and target language are equal') !== false) {
                $updateProgress(12, '原文言語を指定して再試行中');
                $sourceHint = ($userTarget === 'JA') ? 'EN' : 'JA';
                $attempt++;
                continue; // retry create with source_lang hint
            }
            error_log("[DeepL] status=$code message=$detail");
            http_response_code($code ?: 500);
            echo '翻訳開始に失敗しました';
            exit;
        }
        $data = json_decode($res, true);
        $documentId  = $data['document_id']  ?? '';
        $documentKey = $data['document_key'] ?? '';
        if ($documentId === '' || $documentKey === '') {
            http_response_code(500);
            echo '翻訳開始のレスポンスが不正です';
            exit;
        }
        $updateProgress(30, '翻訳中');
        // poll
        while (true) {
            usleep(1500000);
            $statusUrl = $apiBase . '/document/' . rawurlencode($documentId) . '?auth_key=' . rawurlencode($apiKey);
            debug_log('[DeepL] POST ' . redact_url($statusUrl) . ' (status)');
            $ch = curl_init($statusUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
                CURLOPT_POSTFIELDS => http_build_query(['auth_key' => $apiKey, 'document_key' => $documentKey]),
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
            ]);
            curl_setopt($ch, CURLINFO_HEADER_OUT, true);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hdrOut = curl_getinfo($ch, CURLINFO_HEADER_OUT);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($hdrOut) {
                $redacted = preg_replace('/(Authorization:\s*DeepL-Auth-Key\s+)[^\r\n]+/i', '$1[REDACTED]', $hdrOut);
                $redacted = preg_replace('/(auth_key=)[^\s&]+/i', '$1[REDACTED]', $redacted);
                debug_log('[DeepL] request-headers: ' . trim($redacted));
            }
            if ($res === false || $code >= 400) {
                $detail = $res ? (json_decode($res, true)['message'] ?? $res) : $err;
                error_log("[DeepL] status=$code message=$detail");
                http_response_code($code ?: 500);
                echo '翻訳状況の取得に失敗しました';
                exit;
            }
            $info = json_decode($res, true);
            $status = $info['status'] ?? '';
            if ($status === 'done') {
                $billed = (int)($info['billed_characters'] ?? 0);
                if ($billed === 0 && in_array($ext, ['pdf','doc','docx','pptx','xlsx'], true)) {
                    $billed = 50000;
                    error_log('[DeepL] billed_characters missing, using fallback 50000');
                    $fallbackApplied = true;
                }
                $estCost = $billed / 1000000 * $price;
                break 2; // exit attempts loop
            }
            if ($status === 'error') {
                $msg = $info['message'] ?? 'DeepLエラー';
                if ($attempt === 0 && is_string($msg) && stripos($msg, 'Source and target language are equal') !== false) {
                    error_log('[DeepL] status=error (equal-language), retry with source_lang hint');
                    $updateProgress(12, '原文言語を指定して再試行中');
                    $sourceHint = ($userTarget === 'JA') ? 'EN' : 'JA';
                    $attempt++;
                    // restart: create new document with source_lang hint
                    break; // break polling loop to outer while to recreate
                }
                error_log("[DeepL] status=error message=$msg");
                http_response_code(500);
                echo $msg;
                exit;
            }
            $updateProgress(30, '翻訳中...');
        }
        // if we broke polling because of retry request, continue outer while to recreate
        if ($attempt > 0) {
            continue;
        }
    }
    $updateProgress(80, '結果を取得中');
    $resultUrl = $apiBase . '/document/' . rawurlencode($documentId) . '/result' . '?auth_key=' . rawurlencode($apiKey);
    debug_log('[DeepL] POST ' . redact_url($resultUrl) . ' (result)');
    $ch = curl_init($resultUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
        CURLOPT_POSTFIELDS => http_build_query(['auth_key' => $apiKey, 'document_key' => $documentKey]),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    $fileData = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrOut = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($hdrOut) {
        $redacted = preg_replace('/(Authorization:\s*DeepL-Auth-Key\s+)[^\r\n]+/i', '$1[REDACTED]', $hdrOut);
        $redacted = preg_replace('/(auth_key=)[^\s&]+/i', '$1[REDACTED]', $redacted);
        debug_log('[DeepL] request-headers: ' . trim($redacted));
    }
    if ($fileData === false || $code >= 400) {
        $detail = $fileData ? (json_decode($fileData, true)['message'] ?? $fileData) : $err;
        error_log("[DeepL] status=$code message=$detail");
        http_response_code($code ?: 500);
        echo '翻訳結果の取得に失敗しました';
        exit;
    }
    $outDir = __DIR__ . '/downloads';
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
        error_log('Failed to create downloads directory: ' . $outDir);
        http_response_code(500);
        echo '出力ディレクトリの作成に失敗しました';
        exit;
    }
    // Save or convert result depending on requested output
    if ($outputFormat === 'txt') {
        // Save temp DOCX and extract plain text
        $tmpDocx = tempnam(sys_get_temp_dir(), 'deepl_') . '.docx';
        if (file_put_contents($tmpDocx, $fileData) === false) {
            error_log('Failed to save temp DOCX for TXT extraction');
            http_response_code(500);
            echo '一時ファイルの保存に失敗しました';
            exit;
        }
        $outExt = 'txt';
        $baseName = preg_replace('/_(jp|en)$/i', '', pathinfo($filename, PATHINFO_FILENAME));
        $outName = $baseName . '_' . $suffix . '.' . $outExt;
        $outPath = $outDir . '/' . $outName;
        $extracted = '';
        $zip = new ZipArchive();
        if ($zip->open($tmpDocx) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                $extracted = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        @unlink($tmpDocx);
        if (trim((string)$extracted) === '') {
            error_log('Failed to extract text from translated DOCX');
            http_response_code(500);
            echo 'TXT変換に失敗しました';
            exit;
        }
        if (file_put_contents($outPath, $extracted) === false) {
            error_log('Failed to save TXT output: ' . $outPath);
            http_response_code(500);
            echo '翻訳結果の保存に失敗しました';
            exit;
        }
    } else {
        $outExt = ($outputFormat === 'docx') ? 'docx' : ($outputFormat === 'pdf' ? 'pdf' : ($ext === 'doc' ? 'docx' : $ext));
        $baseName = preg_replace('/_(jp|en)$/i', '', pathinfo($filename, PATHINFO_FILENAME));
        $outName = $baseName . '_' . $suffix . '.' . $outExt;
        $outPath = $outDir . '/' . $outName;
        if (file_put_contents($outPath, $fileData) === false) {
            error_log('Failed to save translated file: ' . $outPath);
            http_response_code(500);
            echo '翻訳結果の保存に失敗しました';
            exit;
        }
    }
    $updateProgress(100, '完了');
}

$estCostLog = number_format($estCost, 2, '.', '');
$logMsg = sprintf('[DeepL] status=done billed=%d est_cost=%s%s output=%s', $billed, $priceCcy, $estCostLog, $outPath);
if ($fallbackApplied) {
    $logMsg .= ' fallback_min_charge_applied';
}
error_log($logMsg);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir) && !mkdir($logDir, 0777, true)) {
    error_log('Failed to create log directory: ' . $logDir);
} else {
    $historyPath = $logDir . '/history.csv';
    $fh = fopen($historyPath, 'a');
    if ($fh === false) {
        error_log('Failed to open history file: ' . $historyPath);
    } else {
        if (flock($fh, LOCK_EX)) {
            $row = [$filename, $billed, $estCostLog];
            if (fputcsv($fh, $row) === false) {
                error_log('Failed to write history row for ' . $filename);
            }
            // 併せて出力ファイル名でも履歴を残す（downloads の突合せ強化用）
            if ($outName !== '') {
                $rowOut = [$outName, $billed, $estCostLog];
                if (fputcsv($fh, $rowOut) === false) {
                    error_log('Failed to write history row for output ' . $outName);
                }
            }
            // 実文字数（原文）の補完: 可能であれば原稿ファイルから概算を記録
            $rawLocal = count_chars_light($src);
            if ($rawLocal !== false) {
                $rowRaw = [$filename, (int)$rawLocal, $estCostLog];
                if (fputcsv($fh, $rowRaw) === false) {
                    error_log('Failed to write history raw row for ' . $filename);
                }
            }
            flock($fh, LOCK_UN);
        } else {
            error_log('Failed to lock history file: ' . $historyPath);
        }
        fclose($fh);
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>翻訳完了</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
  <h1>翻訳完了</h1>
  <nav><a href="index.html">トップに戻る</a></nav>
</header>
<main class="card">
  <p><a href="downloads/<?= h($outName) ?>" download>翻訳結果をダウンロード</a></p>
  <p>課金対象文字数: <?= h(number_format($billed)) ?></p>
  <p>概算コスト: <?= h($priceCcy . ' ' . number_format($estCost, 2)) ?></p>
</main>
<footer>&copy; 2025 翻訳ツール</footer>
</body>
</html>
