<?php
// upload_file.php：文字数計算 + 翻訳実行（出力形式選択可）
require_once __DIR__ . '/vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIO;

const MAX_BYTES = 20 * 1024 * 1024; // 20 MB
const RATE_JPY_PER_MILLION = 2500;

$message = '';
$step = 'form';
$filename = '';
$chars = 0;
$costJpy = 0;

function count_chars_local(string $p, string $e): int|false {
  return match (strtolower($e)) {
    'txt' => mb_strlen(file_get_contents($p)),
    'pdf' => mb_strlen((new PdfParser())->parseFile($p)->getText()),
    'docx' => (function ($q) {
      $w = WordIO::load($q);
      $t = '';
      foreach ($w->getSections() as $s) {
        foreach ($s->getElements() as $e) {
          if (method_exists($e, 'getText')) $t .= $e->getText();
        }
      }
      return mb_strlen($t);
    })($p),
    default => false,
  };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stage'] ?? '') === 'upload') {
  if (!empty($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
    if ($_FILES['upload_file']['size'] > MAX_BYTES) {
      $message = '20MB 超えています';
    } else {
      $dir = __DIR__ . '/uploads/';
      if (!is_dir($dir)) mkdir($dir, 0777, true);

      $orig = basename($_FILES['upload_file']['name']);
      $base = pathinfo($orig, PATHINFO_FILENAME);
      $ext = pathinfo($orig, PATHINFO_EXTENSION);
      $date = date('Ymd');
      $candidate = $date . '_' . $base . '.' . $ext;
      $n = 1;
      while (is_file($dir . $candidate)) {
        $candidate = $date . '_' . $n . '_' . $base . '.' . $ext;
        $n++;
      }

      $filename = $candidate;
      $dest = $dir . $filename;

      if (!move_uploaded_file($_FILES['upload_file']['tmp_name'], $dest)) {
        $message = 'アップロードに失敗しました';
      } else {
        $chars = count_chars_local($dest, $ext);
        if ($chars === false) {
          $message = '非対応形式';
        } else {
          $costJpy = round($chars / 1_000_000 * RATE_JPY_PER_MILLION);
          $logDir = __DIR__ . '/logs';
          if (!is_dir($logDir)) mkdir($logDir, 0777, true);
          file_put_contents($logDir . '/history.csv', sprintf("%s,%d,%d\n", $filename, $chars, time()), FILE_APPEND);
          $step = 'confirm';
        }
      }
    }
  } else {
    $message = 'ファイル選択エラー';
  }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>ファイルアップロード</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .upload-section { margin-top: 1em; }
  </style>
</head>
<body>
<header>
  <h1>ファイルアップロード</h1>
  <nav><a href="index.html">トップへ</a></nav>
</header>
<aside>
  <ul>
    <li><a href="upload_file.php">アップロード</a></li>
    <li><a href="downloads.php">翻訳済みDL</a></li>
    <li><a href="manage.php">ファイル管理</a></li>
  </ul>
</aside>
<main>
<div class="card">
<?php if ($step === 'form'): ?>
  <h2>アップロード</h2>
  <?php if ($message): ?><p style="color:red;"><?= $message ?></p><?php endif; ?>
  <form action="upload_file.php" method="post" enctype="multipart/form-data">
    <input type="hidden" name="stage" value="upload">
    <div class="upload-section">
      <input type="file" name="upload_file" required><br><br>
      <button type="submit">アップロード・文字数確認</button>
    </div>
  </form>
<?php elseif ($step === 'confirm'): ?>
  <h2>アップロード結果</h2>
  <p>ファイル名: <?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?></p>
  <p>文字数: <?= number_format($chars) ?> 文字</p>
  <p>概算料金: ￥<?= number_format($costJpy) ?></p>
  <form action="translate.php" method="post">
      <input type="hidden" name="filename" value="<?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>">
    <label>出力形式:
      <select name="out_fmt" required>
        <option value="pdf">PDF</option>
        <option value="docx">DOCX</option>
      </select>
    </label>
    <button type="submit">翻訳実行</button>
  </form>
<?php endif; ?>
</div>
</main>
</body>
</html>
