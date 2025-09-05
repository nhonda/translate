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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$apiKey = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
$glossaries = [];
if ($apiKey !== '' && $apiBase !== '') {
    $ch = curl_init($apiBase . '/glossaries?auth_key=' . rawurlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res !== false && $code < 400) {
        $data = json_decode($res, true);
        if (isset($data['glossaries']) && is_array($data['glossaries'])) {
            $glossaries = $data['glossaries'];
        }
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>用語集管理</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'includes/spinner.php'; ?>
<header>
  <h1>用語集管理</h1>
  <nav><a href="index.html">トップに戻る</a></nav>
</header>

<aside>
  <ul>
    <li><a href="upload_file.php">ファイル アップロード</a></li>
    <li><a href="downloads.php">翻訳済みDL</a></li>
    <li><a href="manage.php">ファイル管理</a></li>
    <li><a href="glossary.php">用語集管理</a></li>
  </ul>
</aside>

<main>
<div class="card">
<h2>用語集一覧</h2>

<?php if (empty($glossaries)): ?>
<p>用語集がありません。</p>
<?php else: ?>
<table class="data-table">
<thead><tr><th>名前</th><th>言語</th><th>項目数</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($glossaries as $g): ?>
<tr>
  <td><?= h($g['name'] ?? '') ?></td>
  <td><?= h(($g['source_lang'] ?? '') . ' → ' . ($g['target_lang'] ?? '')) ?></td>
  <td><?= h($g['entry_count'] ?? '') ?></td>
  <td>
    <form method="post" action="glossary_delete.php" onsubmit="return confirm('削除しますか？');">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="id" value="<?= h($g['glossary_id']) ?>">
      <button type="submit">削除</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<h2 style="margin-top:20px;">＋新規用語集</h2>
<form method="post" action="glossary_save.php" id="glossary-form">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <div>
    <label>名前: <input type="text" name="name" required></label>
  </div>
  <table class="data-table" id="terms-table">
    <thead><tr><th>EN 原文</th><th>JA 訳文</th><th></th></tr></thead>
    <tbody>
      <tr>
        <td><input type="text" name="terms[0][source]" required></td>
        <td><input type="text" name="terms[0][target]" required></td>
        <td><button type="button" class="remove-row">－</button></td>
      </tr>
    </tbody>
  </table>
  <button type="button" id="add-row">＋行追加</button>
  <div class="action-btn">
    <button type="submit">保存</button>
  </div>
</form>
</div>
</main>
<footer>
  &copy; 2025 翻訳ツール
</footer>
<script src="spinner.js"></script>
<script>
document.getElementById('add-row').addEventListener('click', function(){
  const tbody = document.querySelector('#terms-table tbody');
  const idx = tbody.children.length;
  const tr = document.createElement('tr');
  tr.innerHTML = '<td><input type="text" name="terms['+idx+'][source]" required></td>' +
    '<td><input type="text" name="terms['+idx+'][target]" required></td>' +
    '<td><button type="button" class="remove-row">－</button></td>';
  tbody.appendChild(tr);
});

document.getElementById('terms-table').addEventListener('click', function(e){
  if (e.target.classList.contains('remove-row')) {
    const tbody = document.querySelector('#terms-table tbody');
    if (tbody.children.length > 1) {
      e.target.closest('tr').remove();
    }
  }
});

document.getElementById('glossary-form').addEventListener('submit', function(){
  showSpinner('作成中…');
});

Array.from(document.querySelectorAll('form[action="glossary_delete.php"]')).forEach(function(f){
  f.addEventListener('submit', function(){ showSpinner('削除中…'); });
});
</script>
</body>
</html>
