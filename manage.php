<?php
require_once __DIR__ . '/vendor/autoload.php';
const RATE_JPY_PER_MILLION = 2500;
$uploadsDir = __DIR__ . '/uploads';
$files = is_dir($uploadsDir) ? array_diff(scandir($uploadsDir), ['.','..']) : [];
$deleted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ファイル削除
    if (isset($_POST['delete']) && isset($_POST['filename'])) {
        @unlink($uploadsDir . '/' . basename($_POST['filename']));
        header('Location: manage.php?deleted=1');
        exit;
    }
}
$history = [];
if (($h = fopen(__DIR__ . '/logs/history.csv', 'r'))) {
    while (($row = fgetcsv($h)) !== false) {
        if (count($row) < 2) continue;
        [$fn, $chars] = $row;
        $history[$fn] = (int)$chars;
    }
    fclose($h);
}
function cost_jpy(int $c): int {
    return (int)round($c / 1_000_000 * RATE_JPY_PER_MILLION);
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

  <?php if (empty($files)): ?>
    <p>アップロードされているファイルはありません。</p>
  <?php else: ?>
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
          $costDisp = $chars ? '&yen;' . number_format(cost_jpy($chars)) : '未計測';
        ?>
        <tr>
          <td><?= htmlspecialchars($f) ?></td>
          <td><?= $charDisp ?></td>
          <td><?= $costDisp ?></td>
          <td>
            <div class="inline-form-wrap">
              <!-- 翻訳再実行form -->
              <form method="post" action="translate.php">
                <input type="hidden" name="filename" value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>">

                <select name="out_fmt">
                  <option value="pdf">PDF</option>
                  <option value="docx">DOCX</option>
                  <option value="xlsx">XLSX</option>
                </select>
                <button type="submit">翻訳再実行</button>
              </form>
              <!-- 削除form（ボタンで即時削除） -->
              <form method="post" onsubmit="return confirm('本当に削除しますか？');">
                <input type="hidden" name="filename" value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" name="delete" value="1" style="color:red;">削除</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</main>

<footer>&copy; 2025 翻訳ツール</footer>
<script src="spinner.js"></script>
</body>
</html>
