<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
if (file_exists(__DIR__ . '/.env')) {
    $dotenv->load();
}

function env_non_empty(string $key): string {
    $candidates = [];
    if (array_key_exists($key, $_ENV))    { $candidates[] = $_ENV[$key]; }
    if (array_key_exists($key, $_SERVER)) { $candidates[] = $_SERVER[$key]; }
    $getenv = getenv($key);
    if ($getenv !== false) { $candidates[] = $getenv; }
    foreach ($candidates as $v) {
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '') return $t;
        }
    }
    return '';
}

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
$glossaries = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $original = basename($_FILES['file']['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $filename = date('Ymd_His') . '_' . $original;
        $dest = "$uploadsDir/$filename";

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            $message = 'ファイルの保存に失敗しました';
        } else {
            $allowed = ['txt','pdf','docx','xlsx','pptx'];
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
                    // manage.php の選択肢と統一
                    $fmtOptions = [];
                    if ($ext === 'txt') {
                        // TXT は TXT / PDF / DOCX
                        $fmtOptions = ['txt','pdf','docx'];
                    } elseif ($ext === 'pdf') {
                        // PDF は PDF / DOCX
                        $fmtOptions = ['pdf','docx'];
                    } elseif ($ext === 'docx' || $ext === 'doc') {
                        // DOC/DOCX は PDF / DOCX
                        $fmtOptions = ['pdf','docx'];
                    } elseif ($ext === 'xlsx') {
                        // XLSX は XLSX のみ
                        $fmtOptions = ['xlsx'];
                    } elseif ($ext === 'pptx') {
                        // PPTX は PPTX のみ
                        $fmtOptions = ['pptx'];
                    }
                }
            }
        }
    }
}

$apiKey  = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
$defaultGlossary = env_non_empty('DEEPL_GLOSSARY_ID');
if ($step === 'confirm' && $apiKey !== '' && $apiBase !== '') {
    $ch = curl_init($apiBase . '/glossaries?auth_key=' . rawurlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res !== false && $code < 400) {
        $data = json_decode($res, true);
        if (isset($data['glossaries']) && is_array($data['glossaries'])) {
            foreach ($data['glossaries'] as $g) {
                if (!empty($g['glossary_id'])) {
                    $glossaries[] = $g;
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
        // First, try via PHP PDF parser. If empty, fall back to CLI tools.
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($path);
            $text   = $pdf->getText();
        } catch (\Throwable $e) {
            error_log('PDF parsing via Smalot failed: ' . $e->getMessage());
            $text = '';
        }

        // Fallback path if the extracted text is empty or whitespace.
        if (!is_string($text) || trim($text) === '') {
            $pdftotext = trim(shell_exec('command -v pdftotext 2>/dev/null'));
            if ($pdftotext !== '') {
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
            $bufs = [];
            // 1) sharedStrings.xml（共有文字列）
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            if ($xml !== false) {
                $bufs[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            // 2) ワークシートの inlineStr（セル内に直接埋め込まれた文字列）
            for ($i = 1; $i <= 200; $i++) {
                $sheetName = sprintf('xl/worksheets/sheet%d.xml', $i);
                $sx = $zip->getFromName($sheetName);
                if ($sx === false) {
                    if ($i === 1) {
                        // 最初から無ければ以降も無い可能性が高い
                    }
                    break;
                }
                // <c t="inlineStr"><is> ... </is></c> からテキストを抽出
                if (preg_match_all('/<c[^>]*t="inlineStr"[^>]*>.*?<is>(.*?)<\/is>.*?<\/c>/si', $sx, $m)) {
                    foreach ($m[1] as $frag) {
                        // rich text の <t> 要素を優先抽出
                        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/si', $frag, $mt)) {
                            foreach ($mt[1] as $t) {
                                $bufs[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
                            }
                        } else {
                            $bufs[] = html_entity_decode(strip_tags($frag), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                }
            }
            $zip->close();
            $text = trim(implode("\n", array_filter($bufs)));
        }
    } elseif ($ext === 'pptx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $texts = [];
            // Collect text from slides
            for ($i = 1; $i <= 200; $i++) {
                $name = sprintf('ppt/slides/slide%d.xml', $i);
                $xml = $zip->getFromName($name);
                if ($xml === false) {
                    if ($i === 1) {
                        // If first slide not found, likely no more slides
                    }
                    break;
                }
                $texts[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            // Optionally include notes if present
            for ($i = 1; $i <= 200; $i++) {
                $name = sprintf('ppt/notesSlides/notesSlide%d.xml', $i);
                $xml = $zip->getFromName($name);
                if ($xml === false) break;
                $texts[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $zip->close();
            $text = trim(implode("\n", array_filter($texts)));
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
      <li><a href="glossary.php">用語集管理</a></li>
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
          <label for="target_lang">翻訳先：</label>
          <select name="target_lang" id="target_lang">
            <option value="JA">日本語</option>
            <option value="EN-US">英語</option>
          </select>
          <label for="output_format">変換形式：</label>
          <select name="output_format" id="output_format">
            <?php foreach ($fmtOptions as $opt): ?>
              <option value="<?= h($opt) ?>"><?= strtoupper(h($opt)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($glossaries): ?>
            <label for="glossary_id">用語集：</label>
            <select name="glossary_id" id="glossary_id">
              <option value="" <?= $defaultGlossary === '' ? 'selected' : '' ?>>未使用</option>
              <?php foreach ($glossaries as $g): ?>
                <option value="<?= h($g['glossary_id']) ?>" data-source-lang="<?= h($g['source_lang'] ?? '') ?>" data-target-lang="<?= h($g['target_lang'] ?? '') ?>" <?= $g['glossary_id'] === $defaultGlossary ? 'selected' : '' ?>>
                  <?= h(($g['name'] ?? $g['glossary_id']) . ' (' . ($g['source_lang'] ?? '') . '→' . ($g['target_lang'] ?? '') . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <label for="glossary_id">用語集ID：</label>
            <input type="text" name="glossary_id" id="glossary_id" value="<?= h($defaultGlossary) ?>">
          <?php endif; ?>
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
        const tgt = document.getElementById('target_lang').value;
        const gField = document.getElementById('glossary_id');
        if (gField && gField.tagName === 'SELECT') {
          const opt = gField.options[gField.selectedIndex];
          const gTgt = opt ? opt.getAttribute('data-target-lang') : '';
          const norm = s => s.toUpperCase().split('-')[0];
          if (gField.value && gTgt && norm(gTgt) !== norm(tgt)) {
            alert('選択した用語集の言語が翻訳先と一致しません。');
            return;
          }
        }
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
