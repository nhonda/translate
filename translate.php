<?php
session_start();
$sid = session_id();
session_write_close();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
if (file_exists(__DIR__ . '/.env')) {
    $dotenv->load();
}
$apiKey  = $_ENV['DEEPL_API_KEY']  ?? getenv('DEEPL_API_KEY')  ?? '';
$apiBase = rtrim($_ENV['DEEPL_API_BASE'] ?? getenv('DEEPL_API_BASE') ?? '', '/');
$price   = (float)($_ENV['DEEPL_PRICE_PER_MILLION'] ?? getenv('DEEPL_PRICE_PER_MILLION') ?? 25);
$priceCcy = $_ENV['DEEPL_PRICE_CCY'] ?? getenv('DEEPL_PRICE_CCY') ?? 'USD';
if ($apiKey === '' || $apiBase === '') {
    http_response_code(500);
    echo 'DeepL API設定が不足しています';
    exit;
}

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
$allowed = ['pdf','docx','pptx','xlsx','doc'];
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
$updateProgress(10, 'ドキュメントを送信中');

// Upload document
$ch = curl_init($apiBase . '/document');
$postFields = [
    'file' => new CURLFile($src),
    'target_lang' => 'JA',
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
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
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
$billed = 0;
$estCost = 0;
$fallbackApplied = false;
while (true) {
    usleep(1500000);
    $ch = curl_init($apiBase . '/document/' . $documentId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
        CURLOPT_POSTFIELDS => http_build_query(['document_key' => $documentKey]),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
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
$ch = curl_init($apiBase . '/document/' . $documentId . '/result');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
    CURLOPT_POSTFIELDS => http_build_query(['document_key' => $documentKey]),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,
]);
$fileData = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
if ($fileData === false || $code >= 400) {
    $detail = $fileData ? (json_decode($fileData, true)['message'] ?? $fileData) : $err;
    error_log("[DeepL] status=$code message=$detail");
    http_response_code($code ?: 500);
    echo '翻訳結果の取得に失敗しました';
    exit;
}

$outDir = '/var/www/translate/output';
if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    error_log('Failed to create output directory: ' . $outDir);
    http_response_code(500);
    echo '出力ディレクトリの作成に失敗しました';
    exit;
}
$outExt = ($outputFormat === 'docx') ? 'docx' : ($ext === 'doc' ? 'docx' : $ext);
$outName = pathinfo($filename, PATHINFO_FILENAME) . '-ja.' . $outExt;
$outPath = $outDir . '/' . $outName;
if (file_put_contents($outPath, $fileData) === false) {
    error_log('Failed to save translated file: ' . $outPath);
    http_response_code(500);
    echo '翻訳結果の保存に失敗しました';
    exit;
}
$updateProgress(100, '完了');
$estCostLog = number_format($estCost, 2, '.', '');
$logMsg = sprintf('[DeepL] status=done billed=%d est_cost=%s%s output=%s', $billed, $priceCcy, $estCostLog, $outPath);
if ($fallbackApplied) {
    $logMsg .= ' fallback_min_charge_applied';
}
error_log($logMsg);

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
  <p><a href="output/<?= h($outName) ?>" download>翻訳結果をダウンロード</a></p>
  <p>課金対象文字数: <?= h(number_format($billed)) ?></p>
  <p>概算コスト: <?= h($priceCcy . ' ' . number_format($estCost, 2)) ?></p>
</main>
<footer>&copy; 2025 翻訳ツール</footer>
</body>
</html>
