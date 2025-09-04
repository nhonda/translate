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
$outputFormat = in_array($outputFormat, ['pdf', 'docx'], true) ? $outputFormat : '';
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
    $ch = curl_init($translateUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
        // 明示的に auth_key も送る（環境によって Authorization が無視されるのを回避）
        CURLOPT_POSTFIELDS => http_build_query(['auth_key' => $apiKey, 'text' => $text, 'target_lang' => 'JA']),
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
        echo '翻訳に失敗しました';
        exit;
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
    $outName = pathinfo($filename, PATHINFO_FILENAME) . '_jp.txt';
    $outPath = $outDir . '/' . $outName;
    if (file_put_contents($outPath, $translated) === false) {
        error_log('Failed to save translated file: ' . $outPath);
        http_response_code(500);
        echo '翻訳結果の保存に失敗しました';
        exit;
    }
    $billed = mb_strlen($text);
    $estCost = $billed / 1000000 * $price;
    $updateProgress(100, '完了');
} else {
    $updateProgress(10, 'ドキュメントを送信中');
    $docCreateUrl = $apiBase . '/document' . '?auth_key=' . rawurlencode($apiKey);
    debug_log('[DeepL] POST ' . redact_url($docCreateUrl) . ' (document)');
    $ch = curl_init($docCreateUrl);
    $postFields = [
        'file' => new CURLFile($src),
        'target_lang' => 'JA',
        'auth_key' => $apiKey,
    ];
    if ($ext === 'pdf' && $outputFormat !== '') {
        $postFields['output_format'] = $outputFormat;
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
            break;
        }
        if ($status === 'error') {
            $msg = $info['message'] ?? 'DeepLエラー';
            error_log("[DeepL] status=error message=$msg");
            http_response_code(500);
            echo $msg;
            exit;
        }
        $updateProgress(30, '翻訳中...');
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
    $outExt = ($outputFormat === 'docx') ? 'docx' : ($ext === 'doc' ? 'docx' : $ext);
    $outName = pathinfo($filename, PATHINFO_FILENAME) . '_jp.' . $outExt;
    $outPath = $outDir . '/' . $outName;
    if (file_put_contents($outPath, $fileData) === false) {
        error_log('Failed to save translated file: ' . $outPath);
        http_response_code(500);
        echo '翻訳結果の保存に失敗しました';
        exit;
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
