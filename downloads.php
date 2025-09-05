<?php
session_start();
require_once __DIR__ . '/includes/common.php';
const RATE_JPY_PER_MILLION = 2500;
$dir = __DIR__ . '/downloads';
$allFiles = is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];

$history = [];
if (($h = fopen(__DIR__ . '/logs/history.csv', 'r'))) {
  while (($row = fgetcsv($h)) !== false) {
    if (count($row) < 2) continue;
    [$fn, $chars] = $row;
    $base = pathinfo($fn, PATHINFO_FILENAME);
    $val = (int)$chars;
    if (!isset($history[$base])) {
      // Initialize with both raw and billed perspectives
      $history[$base] = [
        'raw' => $val,
        'billed' => max(50000, $val),
      ];
    } else {
      // Keep the smallest as raw (from upload count), and the largest (>=50k) as billed
      $history[$base]['raw'] = min($history[$base]['raw'], $val);
      $history[$base]['billed'] = max($history[$base]['billed'], max(50000, $val));
    }
  }
  fclose($h);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$sort = $_GET['sort'] ?? 'name';
$allowedSorts = ['name', 'mtime', 'chars', 'ext'];
if (!in_array($sort, $allowedSorts, true)) {
  $sort = 'name';
}

usort($allFiles, function($a, $b) use ($sort, $dir, $history) {
  switch ($sort) {
    case 'mtime':
      return filemtime($dir . '/' . $b) <=> filemtime($dir . '/' . $a);
    case 'chars':
      $baseFullA = pathinfo($a, PATHINFO_FILENAME);
      $baseFullB = pathinfo($b, PATHINFO_FILENAME);
      $baseA = preg_replace('/(_(jp|en))+$/i', '', $baseFullA);
      $baseB = preg_replace('/(_(jp|en))+$/i', '', $baseFullB);
      $charA = isset($history[$baseA]) ? ($history[$baseA]['billed'] ?? -1) : (isset($history[$baseFullA]) ? ($history[$baseFullA]['billed'] ?? -1) : -1);
      $charB = isset($history[$baseB]) ? ($history[$baseB]['billed'] ?? -1) : (isset($history[$baseFullB]) ? ($history[$baseFullB]['billed'] ?? -1) : -1);
      return $charB <=> $charA;
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

// 軽量な原文文字数カウント（表示補完用）
function count_chars_light_local(string $path): int|false {
  if (!is_file($path)) return false;
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $text = '';
  if ($ext === 'txt') {
    $text = @file_get_contents($path) ?: '';
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
      $xml = $zip->getFromName('xl/sharedStrings.xml');
      if ($xml !== false) {
        $bufs[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
      }
      for ($i = 1; $i <= 200; $i++) {
        $sheetName = sprintf('xl/worksheets/sheet%d.xml', $i);
        $sx = $zip->getFromName($sheetName);
        if ($sx === false) { if ($i === 1) {} break; }
        if (preg_match_all('/<c[^>]*t="inlineStr"[^>]*>.*?<is>(.*?)<\/is>.*?<\/c>/si', $sx, $m)) {
          foreach ($m[1] as $frag) {
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
      $bufs = [];
      for ($i = 1; $i <= 200; $i++) {
        $name = sprintf('ppt/slides/slide%d.xml', $i);
        $xml = $zip->getFromName($name);
        if ($xml === false) break;
        $bufs[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
      }
      $zip->close();
      $text = trim(implode("\n", $bufs));
    }
  } else {
    return false;
  }
  if (!is_string($text)) return false;
  $text = trim($text);
  if ($text === '') return false;
  return mb_strlen($text, 'UTF-8');
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
  <?php if (isset($_GET['done'])): ?>
    <p style="color:green;"><?= h($_GET['done']) ?> を保存しました。</p>
  <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
      <p style="color:green;">選択したファイルを削除しました。</p>
    <?php endif; ?>

    <?php if ($total === 0): ?>
      <p>まだ翻訳済みファイルがありません。</p>
    <?php else: ?>
      <div class="sort-links">ソート: <a href="?sort=name">名前</a> | <a href="?sort=mtime">更新日時</a> | <a href="?sort=chars">文字数</a> | <a href="?sort=ext">拡張子</a></div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <table class="data-table">
          <thead>
            <tr>
              <th>ファイル名</th>
              <th>文字数</th>
              <th>概算コスト</th>
              <th>ダウンロード</th>
              <th>削除</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($files as $f): ?>
            <?php
              $baseFull = pathinfo($f, PATHINFO_FILENAME);
              $base = preg_replace('/(_(jp|en))+$/i', '', $baseFull);
              $entry = $history[$base] ?? ($history[$baseFull] ?? null);
              $raw = 0;
              $billed = 0;
              if ($entry !== null) {
                $raw = (int)($entry['raw'] ?? 0);
                $billed = (int)($entry['billed'] ?? 0);
              }
              if ($raw <= 0) {
                // フォールバック: uploads ディレクトリから原文ファイルを探して概算
                $uploadDir = __DIR__ . '/uploads';
                $candidates = glob($uploadDir . '/' . $base . '.*');
                foreach ($candidates as $cand) {
                  $r = count_chars_light_local($cand);
                  if ($r !== false && $r > 0) { $raw = $r; break; }
                }
              }
              if ($billed <= 0) {
                $billed = max(50000, $raw);
              }
              $charDisp = $billed > 0 ? number_format($billed) : '未計測';
              if ($billed > 0 && $raw > 0 && $billed !== $raw) {
                $charDisp .= ' (' . number_format($raw) . ')';
              }
              $costDisp = $billed > 0 ? ('¥' . number_format(cost_jpy($billed))) : '未計測';
            ?>
            <tr>
              <td><?= h($f) ?></td>
              <td><?= h($charDisp) ?></td>
              <td><?= h($costDisp) ?></td>
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
    <a href="index.html" class="back-link">トップへ戻る</a>
  </div>
</main>
<footer>&copy; 2025 翻訳ツール</footer>
</body>
</html>
