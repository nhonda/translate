<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';

$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true)) {
    error_log("Failed to create uploads directory: $uploadsDir");
    http_response_code(500);
    die('アップロードディレクトリの作成に失敗しました');
}

$step = 'upload';
$message = '';
$filename = '';
$ext = '';
$outputFormat = $_POST['output_format'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $original = basename($_FILES['file']['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $filename = date('Ymd_His') . '_' . $original;
        $dest = "$uploadsDir/$filename";

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            $message = 'ファイルの保存に失敗しました';
        } else {
            $allowed = ['pdf','docx','pptx','xlsx','doc'];
            $maxSize = 30 * 1024 * 1024; // 30MB
            if (!in_array($ext, $allowed, true)) {
                unlink($dest);
                $message = '非対応形式';
            } elseif (filesize($dest) > $maxSize) {
                unlink($dest);
                $message = '30MBを超えています';
            } else {
                $step = 'confirm';
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>ファイルアップロード</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .card { padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    aside { float: left; width: 200px; }
    main { margin-left: 220px; }
    .error { color: red; }
    .file-input-wrapper { margin-bottom: 10px; }
    #selectedFileName { margin: 6px 0; color: #333; }
  </style>
</head>
<body>
<?php include 'includes/spinner.php'; ?>
  <header>
    <h1>ファイル アップロード</h1>
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
      <?php if ($step === 'upload'): ?>
        <?php if ($message): ?><p class="error"><?= h($message) ?></p><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <div class="file-input-wrapper">
            <input type="file" name="file" id="fileInput" style="display:none;" required>
            <button type="button" onclick="document.getElementById('fileInput').click();">参照</button>
          </div>
          <div id="selectedFileName">ファイルが選択されていません。</div>
          <div class="file-input-wrapper">
            <button type="submit">アップロード</button>
          </div>
        </form>

      <?php else: ?>
        <h2>アップロード結果</h2>
        <p>ファイル名: <?= h($filename) ?></p>
        <form id="translateForm" action="translate.php" method="post">
          <input type="hidden" name="filename" value="<?= h($filename) ?>">
          <label for="output_format">出力形式（PDFアップ時のみDOCX可）</label>
          <select name="output_format" id="output_format">
            <option value="" <?= $outputFormat === '' ? 'selected' : '' ?>></option>
            <option value="pdf" <?= $outputFormat === 'pdf' ? 'selected' : '' ?>>pdf</option>
            <option value="docx" <?= $outputFormat === 'docx' ? 'selected' : '' ?>>docx</option>
          </select>
          <button type="submit">翻訳を開始</button>
        </form>
      <?php endif; ?>
    </div>
  </main>

  <footer>&copy; 2025 翻訳ツール</footer>
  <script src="spinner.js"></script>
  <script>
    const fileInput = document.getElementById('fileInput');
    if (fileInput) {
      fileInput.addEventListener('change', function(){
        const name = this.files.length ? this.files[0].name : 'ファイルが選択されていません。';
        document.getElementById('selectedFileName').textContent = name;
      });
    }
  </script>
</body>
</html>
