<?php
require_once __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;

const RATE_JPY_PER_MILLION = 2500;

$dotenv = Dotenv::createImmutable(__DIR__);
if (file_exists(__DIR__.'/.env')) {
    $dotenv->load();
}

$uploadsDir = __DIR__ . '/uploads';
$logDir     = __DIR__ . '/logs';
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true)) {
    error_log("Failed to create uploads directory: $uploadsDir");
    http_response_code(500);
    die('アップロードディレクトリの作成に失敗しました');
}
if (!is_dir($logDir) && !mkdir($logDir, 0777, true)) {
    error_log("Failed to create log directory: $logDir");
    http_response_code(500);
    die('ログディレクトリの作成に失敗しました');
}

$step = 'upload';
$message = '';
$filename = '';
$ext = '';
$rawChars = 0;
$costJpy = 0;
$fmtOptions = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $original = basename($_FILES['file']['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $filename = date('Ymd_His') . '_' . $original;
        $dest = "$uploadsDir/$filename";

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            $message = 'ファイルの保存に失敗しました';
        } else {
            $allowed = ['txt','pdf','docx','xlsx'];
            if (!in_array($ext, $allowed, true)) {
                unlink($dest);
                $message = '非対応形式';
            } else {
                $rawChars = count_chars_local($dest, $ext);
                if ($rawChars === false) {
                    error_log("Failed to read uploaded file: $dest");
                    unlink($dest);
                    http_response_code(500);
                    die('ファイルの読み込みに失敗しました');
                }
                $costJpy = round(max(50000, $rawChars) / 1_000_000 * RATE_JPY_PER_MILLION);

                if (file_put_contents(
                    "$logDir/history.csv",
                    sprintf("%s,%d,%d\n", $filename, $rawChars, time()),
                    FILE_APPEND
                ) === false) {
                    error_log('Failed to write history log');
                    unlink($dest);
                    http_response_code(500);
                    die('ログ書き込みに失敗しました');
                }

                $step = 'confirm';
                if ($ext === 'txt') {
                    $fmtOptions = '<option value="pdf">PDF</option><option value="docx">DOCX</option>';
                } elseif ($ext === 'pdf') {
                    $fmtOptions = '<option value="pdf">PDF</option><option value="docx">DOCX</option>';
                } elseif ($ext === 'xlsx') {
                    $fmtOptions = '<option value="xlsx">XLSX</option>';
                } else {
                    $fmtOptions = '<option value="pdf">PDF</option><option value="docx">DOCX</option><option value="xlsx">XLSX</option>';
                }
            }
        }
    }
}

function count_chars_local(string $path, string $ext): int|false {
    if (!file_exists($path)) return false;
    $text = '';
    if ($ext === 'txt') {
        $text = file_get_contents($path);
        if ($text === false) {
            error_log("Failed to read text file: $path");
            return false;
        }
    } elseif ($ext === 'pdf') {
        $parser = new Parser();
        $pdf = $parser->parseFile($path);
        $text = $pdf->getText();
    } elseif ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($path)) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $text = strip_tags($xml);
        }
    } elseif ($ext === 'xlsx') {
        $spreadsheet = IOFactory::load($path);
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, true, true, true) as $row) {
                $text .= implode("\t", $row) . "\n";
            }
        }
    }
    return $text ? mb_strlen($text, 'UTF-8') : false;
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
        <?php if ($message): ?><p class="error"><?= htmlspecialchars($message) ?></p><?php endif; ?>
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
        <p>ファイル名: <?= htmlspecialchars($filename) ?></p>
        <p>文字数：<?= number_format($rawChars) ?>字</p>
        <p>概算コスト：￥<?= number_format($costJpy) ?></p>
        <form action="translate.php" method="post">
          <input type="hidden" name="filename" value="<?= htmlspecialchars($filename) ?>">
          <label for="out_fmt">変換形式：</label>
          <select name="out_fmt" id="out_fmt">
            <?= $fmtOptions ?>
          </select>
          <button type="submit">翻訳を開始</button>
        </form>
      <?php endif; ?>
    </div>
  </main>

  <footer>&copy; 2025 翻訳ツール</footer>

  <script>
    document.getElementById('fileInput').addEventListener('change', function(){
      const name = this.files.length ? this.files[0].name : 'ファイルが選択されていません。';
      document.getElementById('selectedFileName').textContent = name;
    });
  </script>
</body>
</html>
