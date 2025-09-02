<?php
session_start();
require_once __DIR__ . '/includes/common.php';
$dir = __DIR__ . '/downloads';
$files = is_dir($dir) ? array_diff(scandir($dir), ['.', '..']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }
  if (!empty($_POST['delete'])) {
    foreach ($_POST['delete'] as $f) {
      $target = realpath($dir . '/' . $f);
      if ($target !== false && strpos($target, $dir . DIRECTORY_SEPARATOR) === 0 && is_file($target)) {
        unlink($target);
      } else {
        error_log('Invalid delete path: ' . $f);
      }
    }
    header('Location: downloads.php?deleted=1');
    exit;
  }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>翻訳済みファイル一覧</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
  <h1>翻訳済みファイル一覧</h1>
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
  <?php if (isset($_GET['done'])): ?>
    <p style="color:green;"><?= h($_GET['done']) ?> を保存しました。</p>
  <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
      <p style="color:green;">選択したファイルを削除しました。</p>
    <?php endif; ?>

    <?php if (empty($files)): ?>
      <p>まだ翻訳済みファイルがありません。</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <table class="data-table">
          <thead>
            <tr>
              <th>ファイル名</th>
              <th>ダウンロード</th>
              <th>削除</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($files as $f): ?>
            <tr>
              <td><?= h($f) ?></td>
              <td><a href="downloads/<?= h(rawurlencode($f)) ?>" download>ダウンロード</a></td>
              <td style="text-align:center">
                <input type="checkbox" name="delete[]" value="<?= h($f) ?>">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <button type="submit" class="action-btn">チェックしたファイルを削除</button>
      </form>
    <?php endif; ?>
    <a href="index.html" class="back-link">トップへ戻る</a>
  </div>
</main>
<footer>&copy; 2025 翻訳ツール</footer>
</body>
</html>
