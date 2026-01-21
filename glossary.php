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

// 用語集の表示順を保持する（編集時も順序を維持）
$orderFile = __DIR__ . '/logs/glossary_order.json';
$order = [];
$orderChanged = false;
$currentIds = array_map(fn($g) => (string)($g['glossary_id'] ?? ''), $glossaries);

// 既存順序の読み込み
if (is_file($orderFile)) {
    $raw = @file_get_contents($orderFile);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $order = array_values(array_filter(array_map('strval', $decoded)));
    }
}

// 初期化：ファイルが無い/壊れている場合は現在の並びを保存
if (empty($order) && !empty($currentIds)) {
    $order = $currentIds;
    $orderChanged = true;
}

// 更新処理で置換されたIDを反映（旧ID→新ID）
if (!empty($_SESSION['glossary_replace_map']) && is_array($_SESSION['glossary_replace_map'])) {
    foreach ($_SESSION['glossary_replace_map'] as $pair) {
        $old = (string)($pair['old'] ?? '');
        $new = (string)($pair['new'] ?? '');
        if ($new === '') continue;
        $idx = array_search($old, $order, true);
        if ($idx !== false) {
            $order[$idx] = $new;
            $orderChanged = true;
        } else {
            // 旧IDが見つからない場合は末尾に追加
            if (!in_array($new, $order, true)) {
                $order[] = $new;
                $orderChanged = true;
            }
        }
    }
    unset($_SESSION['glossary_replace_map']);
}

// 現在存在しないIDを順序から除去し、未登録の新IDを末尾に追加
if (!empty($order)) {
    $existing = array_flip($currentIds); // id => index
    $order = array_values(array_filter($order, function($id) use ($existing) { return isset($existing[$id]); }));
    foreach ($currentIds as $id) {
        if (!in_array($id, $order, true)) {
            $order[] = $id;
            $orderChanged = true;
        }
    }
}

// 並び替え
if (!empty($order) && !empty($glossaries)) {
    $pos = array_flip($order);
    usort($glossaries, function($a, $b) use ($pos) {
        $ia = $pos[$a['glossary_id']] ?? PHP_INT_MAX;
        $ib = $pos[$b['glossary_id']] ?? PHP_INT_MAX;
        return $ia <=> $ib;
    });
}

// 変更があれば保存
if ($orderChanged) {
    @file_put_contents($orderFile, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
<?php if (isset($_GET['created'])): ?>
  <p style="color:green;">用語集を作成しました。</p>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
  <p style="color:green;">用語集を更新しました。</p>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
  <p style="color:green;">用語集を削除しました。</p>
<?php endif; ?>

<?php if (empty($glossaries)): ?>
<p>用語集がありません。</p>
<?php else: ?>
<table class="data-table">
<thead><tr><th>名前</th><th>言語</th><th>項目数</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($glossaries as $g): ?>
<tr>
  <td><?= h($g['name'] ?? '') ?></td>
  <td><?= h(strtoupper($g['source_lang'] ?? '') . ' → ' . strtoupper($g['target_lang'] ?? '')) ?></td>
  <td><?= h($g['entry_count'] ?? '') ?></td>
  <td>
    <div style="display:flex; gap:8px; align-items:center;">
      <form method="get" action="glossary_edit.php">
        <input type="hidden" name="id" value="<?= h($g['glossary_id']) ?>">
        <button type="submit">編集</button>
      </form>
      <form method="post" action="glossary_delete.php" onsubmit="return confirm('削除しますか？');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="id" value="<?= h($g['glossary_id']) ?>">
        <button type="submit">削除</button>
      </form>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<h2 style="margin-top:20px;">新規用語集</h2>
<form method="post" action="glossary_save.php" id="glossary-form">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <div>
    <label>名前: <input type="text" name="name" value="デフォルトの用語集" required></label>
  </div>
  <div style="margin:8px 0;">
    <label>言語方向: 
      <select name="direction" id="direction">
        <option value="EN-JA" selected>EN → JA</option>
        <option value="JA-EN">JA → EN</option>
      </select>
    </label>
    <input type="hidden" name="source_lang" id="source_lang" value="en">
    <input type="hidden" name="target_lang" id="target_lang" value="ja">
  </div>
  <table class="data-table" id="terms-table">
    <thead><tr><th><span id="src-label">英語</span></th><th><span id="tgt-label">日本語</span></th><th></th></tr></thead>
    <tbody>
      <tr>
        <td><input type="text" name="terms[0][source]" required></td>
        <td><input type="text" name="terms[0][target]" required></td>
        <td><button type="button" class="remove-row">－行削除</button></td>
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
    '<td><button type="button" class="remove-row">－行削除</button></td>';
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

// 言語方向の切り替え（表示ラベルを DeepL Pro と同様に「英語」「日本語」に）
const direction = document.getElementById('direction');
const srcLabel = document.getElementById('src-label');
const tgtLabel = document.getElementById('tgt-label');
const srcLang = document.getElementById('source_lang');
const tgtLang = document.getElementById('target_lang');
if (direction) {
  direction.addEventListener('change', function(){
    if (this.value === 'JA-EN') {
      srcLabel.textContent = '日本語';
      tgtLabel.textContent = '英語';
      srcLang.value = 'ja';
      tgtLang.value = 'en';
    } else {
      srcLabel.textContent = '英語';
      tgtLabel.textContent = '日本語';
      srcLang.value = 'en';
      tgtLang.value = 'ja';
    }
  });
}
Array.from(document.querySelectorAll('form[action="glossary_edit.php"]')).forEach(function(f){
  f.addEventListener('submit', function(){ showSpinner('読み込み中…'); });
});
</script>
</body>
</html>
