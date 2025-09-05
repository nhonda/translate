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

$id = trim($_GET['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    exit('Invalid ID');
}

$apiKey = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
if ($apiKey === '' || $apiBase === '') {
    http_response_code(500);
    exit('DeepL API未設定');
}

$name = '';
$sourceLang = 'en';
$targetLang = 'ja';
$terms = [];
// 逆方向の候補
$revId = '';
$revSourceLang = '';
$revTargetLang = '';
$revTerms = [];

// 取得: メタ情報
$ch = curl_init($apiBase . '/glossaries/' . rawurlencode($id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($res === false || $code >= 400) {
    http_response_code(404);
    exit('用語集が見つかりません');
}
$info = json_decode($res, true);
if (is_array($info)) {
    $name = (string)($info['name'] ?? '');
    $sourceLang = (string)($info['source_lang'] ?? 'en');
    $targetLang = (string)($info['target_lang'] ?? 'ja');
}

// 取得: エントリ(TSV)
$ch = curl_init($apiBase . '/glossaries/' . rawurlencode($id) . '/entries');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: DeepL-Auth-Key ' . $apiKey,
        'Accept: text/tab-separated-values'
    ],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($res !== false && $code < 400) {
    $lines = preg_split('/\r?\n/', $res);
    foreach ($lines as $line) {
        if ($line === '') continue;
        $parts = explode("\t", $line, 2);
        if (count($parts) === 2) {
            $terms[] = ['source' => $parts[0], 'target' => $parts[1]];
        }
    }
}
if (empty($terms)) {
    $terms[] = ['source' => '', 'target' => ''];
}

// 同名で言語が逆の用語集を探索
$ch = curl_init($apiBase . '/glossaries?auth_key=' . rawurlencode($apiKey));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,
]);
$resList = curl_exec($ch);
$codeList = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$allGlossaries = [];
if ($resList !== false && $codeList < 400) {
    $data = json_decode($resList, true);
    if (isset($data['glossaries']) && is_array($data['glossaries'])) {
        foreach ($data['glossaries'] as $g) {
            $gid = (string)($g['glossary_id'] ?? '');
            if ($gid === '' || $gid === $id) continue;
            $gname = (string)($g['name'] ?? '');
            $gsrc  = (string)($g['source_lang'] ?? '');
            $gtgt  = (string)($g['target_lang'] ?? '');
            if ($gid !== '') {
                $allGlossaries[] = $g;
            }
            if ($gname === $name && strtolower($gsrc) === strtolower($targetLang) && strtolower($gtgt) === strtolower($sourceLang)) {
                $revId = $gid;
                $revSourceLang = $gsrc;
                $revTargetLang = $gtgt;
                break;
            }
        }
    }
}

if ($revId !== '') {
    $ch = curl_init($apiBase . '/glossaries/' . rawurlencode($revId) . '/entries');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: DeepL-Auth-Key ' . $apiKey,
            'Accept: text/tab-separated-values'
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res !== false && $code < 400) {
        $lines = preg_split('/\r?\n/', $res);
        foreach ($lines as $line) {
            if ($line === '') continue;
            $parts = explode("\t", $line, 2);
            if (count($parts) === 2) {
                $revTerms[] = ['source' => $parts[0], 'target' => $parts[1]];
            }
        }
    }
}
if (empty($revTerms)) {
    $revTerms[] = ['source' => '', 'target' => ''];
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>用語集編集</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'includes/spinner.php'; ?>
<header>
  <h1>用語集編集</h1>
  <nav><a href="glossary.php">一覧に戻る</a></nav>
</header>

<aside>
  <ul>
    <li><a href="upload_file.php">ファイル アップロード</a></li>
    <li><a href="downloads.php">翻訳済みDL</a></li>
    <li><a href="manage.php">ファイル管理</a></li>
    <li><a href="glossary.php">用語集管理</a></li>
  </ul>
  <?php if (!empty($allGlossaries)): ?>
    <ul>
      <?php foreach ($allGlossaries as $gg): ?>
        <?php $gid = (string)($gg['glossary_id'] ?? ''); ?>
        <li>
          <a href="glossary_edit.php?id=<?= h($gid) ?>">
            <?= h(($gg['name'] ?? $gid) . ' (' . strtoupper($gg['source_lang'] ?? '') . '→' . strtoupper($gg['target_lang'] ?? '') . ')') ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  </aside>

<main>
<div class="card">
<?php
function lang_jp_label(string $code): string {
  $c = strtolower($code);
  if ($c === 'en' || $c === 'en-us' || $c === 'en-gb') return '英語';
  if ($c === 'ja') return '日本語';
  return strtoupper($code);
}
?>
<h2>編集: <?= h($name !== '' ? $name : '（名称未設定）') ?></h2>
<form method="post" action="glossary_update.php" id="glossary-edit-form">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <input type="hidden" name="id" value="<?= h($id) ?>">
  <div>
    <label>名前: <input type="text" name="name" value="<?= h($name) ?>" required></label>
  </div>
  <table class="data-table" id="terms-table">
    <thead>
      <tr>
        <th><?= h(lang_jp_label($sourceLang)) ?></th>
        <th style="width:24px; text-align:center;">→</th>
        <th><?= h(lang_jp_label($targetLang)) ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($terms as $i => $t): ?>
      <tr>
        <td><input type="text" name="terms[<?= $i ?>][source]" value="<?= h($t['source']) ?>" required></td>
        <td style="text-align:center;">→</td>
        <td><input type="text" name="terms[<?= $i ?>][target]" value="<?= h($t['target']) ?>" required></td>
        <td><button type="button" class="remove-row">－行削除</button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <button type="button" id="add-row">＋行追加</button>

  

  <div class="action-btn">
    <button type="submit">更新</button>
    <button type="button" onclick="window.location.href='glossary.php'">キャンセル</button>
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
    '<td style="text-align:center;">→</td>' +
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

// 逆方向の行追加・削除UIは非表示（単方向編集）

document.getElementById('glossary-edit-form').addEventListener('submit', function(){
  showSpinner('更新中…');
});
</script>
</body>
</html>
