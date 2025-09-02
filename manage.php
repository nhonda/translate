<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';
const RATE_JPY_PER_MILLION = 2500;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$uploadsDir = __DIR__ . '/uploads';
$allFiles = is_dir($uploadsDir) ? array_values(array_diff(scandir($uploadsDir), ['.','..'])) : [];
$deleted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
    // ファイル削除
    if (isset($_POST['delete']) && isset($_POST['filename'])) {
        $filename = $_POST['filename'];
        $target = realpath($uploadsDir . '/' . $filename);
        if ($target !== false && strpos($target, $uploadsDir . DIRECTORY_SEPARATOR) === 0 && is_file($target)) {
            unlink($target);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            foreach (glob(__DIR__ . "/downloads/{$base}_jp.*") as $translated) {
                if (is_file($translated)) {
                    unlink($translated);
                }
            }
            $historyFile = __DIR__ . '/logs/history.csv';
            $rows = [];
            if (file_exists($historyFile) && ($h = fopen($historyFile, 'r')) !== false) {
                while (($row = fgetcsv($h)) !== false) {
                    if (!isset($row[0]) || $row[0] !== $filename) {
                        $rows[] = $row;
                    }
                }
                fclose($h);
            }
            if (($h = fopen($historyFile, 'w')) !== false) {
                foreach ($rows as $row) {
                    if (fputcsv($h, $row) === false) {
                        error_log('Failed to write history row for ' . $filename);
                        break;
                    }
                }
                fclose($h);
            } else {
                error_log('Failed to write history file: ' . $historyFile);
            }
            header('Location: manage.php?deleted=1');
            exit;
        } else {
            error_log('Invalid delete path: ' . ($_POST['filename'] ?? ''));
            http_response_code(400);
            exit('Invalid file path');
        }
    }
}
$history = [];
$totalChars = 0;
$totalCost = 0;
if (($h = fopen(__DIR__ . '/logs/history.csv', 'r'))) {
    while (($row = fgetcsv($h)) !== false) {
        if (count($row) < 2) continue;
        [$fn, $chars] = $row;
        $chars = (int)$chars;
        $history[$fn] = $chars;
        $totalChars += $chars;
        $totalCost += cost_jpy($chars);
    }
    fclose($h);
}

$sort = $_GET['sort'] ?? 'name';
$allowedSorts = ['name', 'mtime', 'size', 'ext'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'name';
}

usort($allFiles, function($a, $b) use ($sort, $uploadsDir) {
    switch ($sort) {
        case 'mtime':
            return filemtime($uploadsDir . '/' . $b) <=> filemtime($uploadsDir . '/' . $a);
        case 'size':
            return filesize($uploadsDir . '/' . $b) <=> filesize($uploadsDir . '/' . $a);
        case 'ext':
            return strcasecmp(pathinfo($a, PATHINFO_EXTENSION), pathinfo($b, PATHINFO_EXTENSION));
        default:
            return strcasecmp($a, $b);
    }
});

$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = count($allFiles);
$totalPages = (int)ceil($total / $limit);
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $limit;
$files = array_slice($allFiles, $offset, $limit);

function cost_jpy(int $c): int {
    return (int)round(max(50000, $c) / 1_000_000 * RATE_JPY_PER_MILLION);
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>ファイル管理</title>
<link rel="stylesheet" href="style.css">
<style>
/* 必要ならここにCSS追加 */
.inline-form-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
}
.inline-form-wrap form {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 6px;
}
</style>
</head>
<body>
<?php include 'includes/spinner.php'; ?>

<header>
  <h1>アップロード済みファイル一覧</h1>
  <nav><a href="index.html">トップに戻る</a></nav>
</header>

<aside>
  <ul>
    <li><a href="upload_file.php">ファイル アップロード</a></li>
    <li><a href="downloads.php">翻訳済みDL</a></li>
    <li><a href="manage.php">ファイル管理</a></li>
  </ul>
</aside>

<main>
<div class="card">
  <h2>アップロードファイル</h2>

  <?php if (isset($_GET['deleted'])): ?>
    <p style="color:green;">選択したファイルを削除しました。</p>
  <?php endif; ?>

  <?php if ($total === 0): ?>
    <p>アップロードされているファイルはありません。</p>
  <?php else: ?>
    <div class="sort-links">ソート: <a href="?sort=name">名前</a> | <a href="?sort=mtime">更新日時</a> | <a href="?sort=size">サイズ</a> | <a href="?sort=ext">拡張子</a></div>
    <table class="data-table">
      <thead>
        <tr>
          <th>ファイル名</th>
          <th>文字数</th>
          <th>概算コスト</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($files as $f): ?>
        <?php
          $chars = $history[$f] ?? null;
          $charDisp = $chars ? number_format($chars) : '未計測';
          $costDisp = $chars ? '¥' . number_format(cost_jpy($chars)) : '未計測';
        ?>
        <tr>
          <td><?= h($f) ?></td>
          <td><?= h($charDisp) ?></td>
          <td><?= h($costDisp) ?></td>
          <td>
            <div class="inline-form-wrap">
              <!-- 翻訳再実行form -->
              <form method="post" action="translate.php">
                <input type="hidden" name="filename" value="<?= h($f) ?>">

                <select name="out_fmt">
                  <option value="pdf">PDF</option>
                  <option value="docx">DOCX</option>
                  <option value="xlsx">XLSX</option>
                </select>
                <button type="submit">翻訳再実行</button>
              </form>
              <!-- 削除form（ボタンで即時削除） -->
              <form method="post" onsubmit="return confirm('本当に削除しますか？');">
                <input type="hidden" name="filename" value="<?= h($f) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <button type="submit" name="delete" value="1" style="color:red;">削除</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr class="summary-row">
        <td>合計</td>
        <td><?= h(number_format($totalChars)) ?></td>
        <td><?= h('¥' . number_format($totalCost)) ?></td>
        <td></td>
      </tr>
      </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php if ($i === $page): ?>
            <strong><?= $i ?></strong>
          <?php else: ?>
            <a href="?sort=<?= h($sort) ?>&page=<?= $i ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</main>

<footer>&copy; 2025 翻訳ツール</footer>
<script src="spinner.js"></script>
</body>
</html>
