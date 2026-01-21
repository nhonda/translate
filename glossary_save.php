<?php
require_once __DIR__ . '/includes/common.php';
secure_session_start();
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
if (file_exists(__DIR__ . '/.env')) {
    $dotenv->load();
}

function env_non_empty(string $key): string {
    $candidates = [];
    if (array_key_exists($key, $_ENV)) {
        $candidates[] = $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        $candidates[] = $_SERVER[$key];
    }
    $getenv = getenv($key);
    if ($getenv !== false) {
        $candidates[] = $getenv;
    }
    foreach ($candidates as $v) {
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '') {
                return $t;
            }
        }
    }
    return '';
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$postToken = $_POST['csrf_token'] ?? '';
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$name = trim($_POST['name'] ?? '');
$terms = $_POST['terms'] ?? [];
$srcLang = strtolower(trim($_POST['source_lang'] ?? 'en'));
$tgtLang = strtolower(trim($_POST['target_lang'] ?? 'ja'));
// 許可するのは en/ja のみ、かつ同一不可
$allowed = ['en','ja'];
if (!in_array($srcLang, $allowed, true)) { $srcLang = 'en'; }
if (!in_array($tgtLang, $allowed, true)) { $tgtLang = 'ja'; }
if ($srcLang === $tgtLang) { $srcLang = 'en'; $tgtLang = 'ja'; }

$rows = [];
if (is_array($terms)) {
    foreach ($terms as $pair) {
        $src = trim($pair['source'] ?? '');
        $dst = trim($pair['target'] ?? '');
        // 用語内のタブ・改行はスペースに正規化
        $src = str_replace(["\r", "\n", "\t"], ' ', $src);
        $dst = str_replace(["\r", "\n", "\t"], ' ', $dst);
        $src = trim($src);
        $dst = trim($dst);
        if ($src !== '' && $dst !== '') {
            $rows[] = $src . "\t" . $dst;
        }
    }
}

$errors = [];
if ($name === '') {
    $errors[] = '名前を入力してください';
}
if (empty($rows)) {
    $errors[] = '用語を入力してください';
}

$apiKey = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
if ($apiKey === '' || $apiBase === '') {
    $errors[] = 'DeepL APIの設定が必要です';
}

if (empty($errors)) {
    $payload = [
        'name' => $name,
        'source_lang' => $srcLang,
        'target_lang' => $tgtLang,
        'entries' => implode("\n", $rows),
        'entries_format' => 'tsv',
    ];
    $ch = curl_init($apiBase . '/glossaries');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: DeepL-Auth-Key ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res !== false && $code < 400) {
        header('Location: glossary.php?created=1');
        exit;
    } else {
        error_log('glossary_save create failed: HTTP ' . ($code ?: 'n/a') . ' body=' . substr((string)$res, 0, 500));
        $errors[] = '用語集の作成に失敗しました';
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>用語集保存エラー</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
  <h1>用語集保存エラー</h1>
  <nav><a href="index.html">トップに戻る</a></nav>
</header>
<main>
<div class="card">
  <?php foreach ($errors as $e): ?>
    <p style="color:red;"><?= h($e) ?></p>
  <?php endforeach; ?>
  <p><a href="glossary.php">戻る</a></p>
</div>
</main>
</body>
</html>
