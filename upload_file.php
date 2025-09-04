<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';

const RATE_JPY_PER_MILLION = 2500;

$uploadsDir = __DIR__ . '/uploads';
$logDir     = __DIR__ . '/logs';
foreach ([$uploadsDir, $logDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        error_log("Failed to create directory: $dir");
        http_response_code(500);
        die('ディレクトリの作成に失敗しました');
    }
}

$step = 'upload';
$message = '';
$filename = '';
$ext = '';
$rawChars = 0;
$costJpy = 0;
$fmtOptions = [];

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
                $rawChars = count_chars_local($dest);
                if ($rawChars === false) {
                    unlink($dest);
                    $message = '文字数の取得に失敗しました';
                } else {
                    $costJpy = round(max(50000, $rawChars) / 1_000_000 * RATE_JPY_PER_MILLION);
                    file_put_contents("$logDir/history.csv", sprintf("%s,%d,%d\n", $filename, $rawChars, time()), FILE_APPEND);
                    $step = 'confirm';
                    $fmtOptions = [''];
                    if ($ext === 'pdf' || $ext === 'txt') {
                        $fmtOptions[] = 'pdf';
                        $fmtOptions[] = 'docx';
                    } elseif ($ext === 'docx') {
                        $fmtOptions[] = 'pdf';
                        $fmtOptions[] = 'docx';
                    } elseif ($ext === 'xlsx') {
                        $fmtOptions[] = 'xlsx';
                    }
                }
            }
        }
    }
}

function count_chars_local(string $path): int|false {
    if (!is_file($path)) {
        return false;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $text = '';
    if ($ext === 'txt') {
        $text = @file_get_contents($path);
    } elseif ($ext === 'pdf') {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($path);
            $text   = $pdf->getText();
        } catch (\Throwable $e) {
            $pdftotext = trim(shell_exec('command -v pdftotext 2>/dev/null'));
            if ($pdftotext === '') {
                error_log('PDF parsing failed: ' . $e->getMessage());
                return false;
            }
            $text = shell_exec(sprintf('%s %s - 2>/dev/null', $pdftotext, escapeshellarg($path)));
            if ($text === null || trim($text) === '') {
                $qpdf = trim(shell_exec('command -v qpdf 2>/dev/null'));
                if ($qpdf !== '') {
                    $tmp = tempnam(sys_get_temp_dir(), 'qpdf_') . '.pdf';
                    shell_exec(sprintf('%s --stream-data=uncompress %s %s 2>/dev/null', $qpdf, escapeshellarg($path), escapeshellarg($tmp)));
                    $text = shell_exec(sprintf('%s %s - 2>/dev/null', $pdftotext, escapeshellarg($tmp)));
                    @unlink($tmp);
                }
            }
            if ($text === null || trim($text) === '') {
                $ocr       = trim(shell_exec('command -v ocrmypdf 2>/dev/null'));
                $tesseract = trim(shell_exec('command -v tesseract 2>/dev/null'));
                if ($ocr !== '' && $tesseract !== '') {
                    $tmp = tempnam(sys_get_temp_dir(), 'ocr_') . '.pdf';
                    shell_exec(sprintf('%s %s %s 2>/dev/null', $ocr, escapeshellarg($path), escapeshellarg($tmp)));
                    $text = shell_exec(sprintf('%s %s - 2>/dev/null', $pdftotext, escapeshellarg($tmp)));
                    @unlink($tmp);
                }
            }
        }
    } elseif ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    } elseif ($ext === 'xlsx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            $zip->close();
            if ($xml !== false) {
                $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }
    if (!is_string($text)) {
        return false;
    }
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    return mb_strlen($text, 'UTF-8');
}
?>
<!DOCTYPE html>
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
        <p>文字数：<?= h(number_format($rawChars)) ?>字</p>
        <p>概算コスト：￥<?= h(number_format($costJpy)) ?></p>
        <form id="translateForm" action="translate.php" method="post">
          <input type="hidden" name="filename" value="<?= h($filename) ?>">
          <label for="output_format">変換形式：</label>
          <select name="output_format" id="output_format">
            <?php foreach ($fmtOptions as $opt): ?>
              <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
            <?php endforeach; ?>
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

    const form = document.getElementById('translateForm');
    if (form) {
      form.addEventListener('submit', function(e){
        e.preventDefault();
        showSpinner();
        const fd = new FormData(form);
        const poll = () => {
          fetch('progress.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
              updateSpinner(d.percent, d.message);
              if (d.percent >= 100) {
                clearInterval(timer);
              }
            })
            .catch(() => {});
        };
        poll();
        const timer = setInterval(poll, 1000);

        fetch('translate.php', {method: 'POST', body: fd, credentials: 'same-origin'})
          .then(res => {
            if (!res.ok) throw new Error('翻訳に失敗しました');
            return res;
          })
          .then(res => {
            clearInterval(timer);
            if (res.redirected) {
              window.location.href = res.url;
            } else {
              hideSpinner();
            }
          })
          .catch(err => {
            clearInterval(timer);
            hideSpinner();
            alert(err.message || '翻訳に失敗しました');
          });
      });
    }
  </script>
</body>
</html>
