<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$id = trim($_POST['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    exit('Invalid ID');
}

$apiKey = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');

$errors = [];
if ($apiKey === '' || $apiBase === '') {
    $errors[] = 'DeepL APIの設定が必要です';
}

if (empty($errors)) {
    // DeepL API: 用語集の削除
    $ch = curl_init($apiBase . '/glossaries/' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) {
        $errors[] = '用語集の削除に失敗しました';
    } else {
        // 表示順ファイルからIDを除去（存在すれば）
        $orderFile = __DIR__ . '/logs/glossary_order.json';
        if (is_file($orderFile)) {
            $raw = @file_get_contents($orderFile);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $newOrder = array_values(array_filter(array_map('strval', $decoded), function($v) use ($id) {
                    return $v !== $id;
                }));
                if ($newOrder !== $decoded) {
                    @file_put_contents($orderFile, json_encode($newOrder, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        }
        header('Location: glossary.php?deleted=1');
        exit;
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>用語集削除エラー</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
  <h1>用語集削除エラー</h1>
  <nav><a href="glossary.php">一覧に戻る</a></nav>
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

