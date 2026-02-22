<?php
require_once __DIR__ . '/includes/common.php';
secure_session_start();
require_once __DIR__ . '/vendor/autoload.php';
const RATE_JPY_PER_MILLION = 2500;

// .env を読み込む（DeepLの設定値のため）
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// 環境変数取得ヘルパ
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
            foreach (["{$base}_jp.*", "{$base}_en.*"] as $pattern) {
                foreach (glob(__DIR__ . "/downloads/{$pattern}") as $translated) {
                    if (is_file($translated)) {
                        unlink($translated);
                    }
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
if (($h = fopen(__DIR__ . '/logs/history.csv', 'r'))) {
    while (($row = fgetcsv($h)) !== false) {
        if (count($row) < 2) continue;
        [$fn, $chars] = $row;
        $val = (int)$chars;
        if (!isset($history[$fn])) {
            $history[$fn] = [
                'raw' => $val,
                'billed' => max(50000, $val),
            ];
        } else {
            $history[$fn]['raw'] = min($history[$fn]['raw'], $val);
            $history[$fn]['billed'] = max($history[$fn]['billed'], max(50000, $val));
        }
    }
    fclose($h);
}

$sort = $_GET['sort'] ?? 'name';
$allowedSorts = ['name', 'mtime', 'size', 'ext'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'name';
}

usort($allFiles, function($a, $b) use ($sort, $uploadsDir, $history) {
    switch ($sort) {
        case 'mtime':
            return filemtime($uploadsDir . '/' . $b) <=> filemtime($uploadsDir . '/' . $a);
        case 'size':
            $sizeA = isset($history[$a]) ? ($history[$a]['billed'] ?? null) : null;
            $sizeB = isset($history[$b]) ? ($history[$b]['billed'] ?? null) : null;
            $sizeA = $sizeA !== null ? $sizeA : (int)filesize($uploadsDir . '/' . $a);
            $sizeB = $sizeB !== null ? $sizeB : (int)filesize($uploadsDir . '/' . $b);
            return $sizeB <=> $sizeA;
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
    <li><a href="glossary.php">用語集管理</a></li>
  </ul>
</aside>

<main>
<div class="card">
  <h2>アップロードファイル</h2>

  <?php
    // DeepL用語集の取得（選択肢）
    $apiKey  = env_non_empty('DEEPL_API_KEY');
    if ($apiKey === '') { $apiKey = env_non_empty('DEEPL_AUTH_KEY'); }
    $apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
    $defaultGlossary = '';
    $glossaries = [];
    if ($apiKey !== '' && $apiBase !== '') {
        $ch = curl_init($apiBase . '/glossaries');
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
                // 既定は「未使用」にする（自動選択なし）
                // 表示順を登録順に合わせる（glossary.php と同様に保存された順序を適用）
                $orderFile = __DIR__ . '/logs/glossary_order.json';
                if (!empty($glossaries) && is_file($orderFile)) {
                    $raw = @file_get_contents($orderFile);
                    $order = $raw !== false ? json_decode($raw, true) : null;
                    if (is_array($order) && !empty($order)) {
                        $pos = array_flip(array_map('strval', $order));
                        usort($glossaries, function($a, $b) use ($pos) {
                            $ia = $pos[$a['glossary_id']] ?? PHP_INT_MAX;
                            $ib = $pos[$b['glossary_id']] ?? PHP_INT_MAX;
                            return $ia <=> $ib;
                        });
                    }
                }
            }
        }
    }
  ?>

  <?php if (isset($_GET['deleted'])): ?>
    <p style="color:green;">選択したファイルを削除しました。</p>
  <?php endif; ?>

  <?php if ($total === 0): ?>
    <p>アップロードされているファイルはありません。</p>
  <?php else: ?>
      <div class="sort-links">ソート: <a href="?sort=name">名前</a> | <a href="?sort=mtime">更新日時</a> | <a href="?sort=size">文字数</a> | <a href="?sort=ext">拡張子</a></div>
    <table class="data-table">
      <thead>
        <tr>
          <th>ファイル名</th>
          <th>文字数</th>
          <th>概算コスト</th>
          <th>用語集</th>
          <th>翻訳言語</th>
          <th>出力形式</th>
          <th>翻訳再実行</th>
          <th>削除</th>
        </tr>
      </thead>
      <tbody>
      <?php $rowIndex = 0; foreach ($files as $f): ?>
        <?php
          $entry = $history[$f] ?? null;
          if ($entry !== null) {
            $raw = (int)($entry['raw'] ?? 0);
            $billed = (int)($entry['billed'] ?? max(50000, $raw));
            $charDisp = number_format($billed);
            if ($billed !== $raw && $raw > 0) {
              $charDisp .= ' (' . number_format($raw) . ')';
            }
            $costDisp = '¥' . number_format(cost_jpy($billed));
          } else {
            $charDisp = '未計測';
            $costDisp = '未計測';
          }
          $formId = 'tf_' . $rowIndex++;
          // 出力形式候補
          $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
          $fmtOptions = [];
          if ($ext === 'pdf') {
            $fmtOptions = ['pdf','docx'];
          } elseif ($ext === 'docx' || $ext === 'doc') {
            $fmtOptions = ['pdf','docx'];
          } elseif ($ext === 'xlsx') {
            $fmtOptions = ['xlsx'];
          } elseif ($ext === 'pptx') {
            $fmtOptions = ['pptx'];
          } elseif ($ext === 'txt') {
            $fmtOptions = ['txt','pdf','docx'];
          }
        ?>
        <tr>
          <td><a href="uploads/<?= h(rawurlencode($f)) ?>" download><?= h($f) ?></a></td>
          <td><?= h($charDisp) ?></td>
          <td><?= h($costDisp) ?></td>
          <td>
            <?php if ($glossaries): ?>
              <select name="glossary_id" class="glossary-select" form="<?= h($formId) ?>">
                <option value="" selected>未使用</option>
                <?php foreach ($glossaries as $g): ?>
                  <option value="<?= h($g['glossary_id']) ?>" data-source-lang="<?= h($g['source_lang'] ?? '') ?>" data-target-lang="<?= h($g['target_lang'] ?? '') ?>">
                    <?= h(($g['name'] ?? $g['glossary_id']) . ' (' . ($g['source_lang'] ?? '') . '→' . ($g['target_lang'] ?? '') . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <select name="glossary_id" class="glossary-select" form="<?= h($formId) ?>">
                <option value="" selected>未使用</option>
              </select>
            <?php endif; ?>
          </td>
          <td>
            <select name="target_lang" class="target-lang-select" form="<?= h($formId) ?>">
              <option value="JA">日本語</option>
              <option value="EN-US">英語</option>
            </select>
          </td>
          <td>
            <?php if (!empty($fmtOptions)): ?>
              <select name="output_format" form="<?= h($formId) ?>">
                <?php foreach ($fmtOptions as $opt): ?>
                  <option value="<?= h($opt) ?>"><?= strtoupper(h($opt)) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="translate.php" class="translate-form" id="<?= h($formId) ?>">
              <input type="hidden" name="filename" value="<?= h($f) ?>">
              <button type="submit">翻訳再実行</button>
            </form>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('本当に削除しますか？');">
              <input type="hidden" name="filename" value="<?= h($f) ?>">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
              <button type="submit" name="delete" value="1" style="color:red;">削除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php
        // ページ全体の合計（billed と raw）を再計算
        $sumBilled = 0;
        $sumRaw = 0;
        foreach ($allFiles as $uf) {
          if (isset($history[$uf])) {
            $sumBilled += (int)($history[$uf]['billed'] ?? 0);
            $sumRaw    += (int)($history[$uf]['raw'] ?? 0);
          }
        }
        $summaryDisp = number_format($sumBilled);
        if ($sumBilled !== $sumRaw && $sumRaw > 0) {
          $summaryDisp .= ' (' . number_format($sumRaw) . ')';
        }
        $totalCost = cost_jpy($sumBilled);
      ?>
      <tr class="summary-row">
        <td>合計</td>
        <td><?= h($summaryDisp) ?></td>
        <td><?= h('¥' . number_format($totalCost)) ?></td>
        <td colspan="5"></td>
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
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.translate-form').forEach(function(form){
    form.addEventListener('submit', function(e){
      e.preventDefault();
      const sel = document.querySelector('.target-lang-select[form="' + form.id + '"]');
      const tgt = sel ? sel.value : '';
      if (!tgt) return;

      // Glossary のターゲット言語と整合チェック
      const gSel = document.querySelector('.glossary-select[form="' + form.id + '"]');
      let sourceLang = '';
      if (gSel && gSel.value) {
        const opt = gSel.options[gSel.selectedIndex];
        const gTgt = opt ? (opt.getAttribute('data-target-lang') || '') : '';
        sourceLang = opt ? (opt.getAttribute('data-source-lang') || '') : '';

        const norm = s => s.toUpperCase().split('-')[0];
        if (gTgt && norm(gTgt) !== norm(tgt)) {
          alert('選択した用語集の言語が翻訳先と一致しません。');
          return;
        }
      }

      showSpinner('準備中…');
      updateSpinner(0, '準備中…');

      const fd = new FormData(form);
      fd.append('action', 'start');
      if (sourceLang) {
          fd.append('source_lang', sourceLang);
      }

      fetch('translate.php', { method: 'POST', body: fd })
        .then(async r => {
            if (!r.ok) {
                let msg = 'Server Error ' + r.status;
                try {
                    const err = await r.json();
                    if (err.error) msg = err.error;
                } catch (e) {}
                throw new Error(msg);
            }
            return r.json();
        })
        .then(data => {
            if (data.error) throw new Error(data.error);
            if (data.status !== 'queued') throw new Error('Unexpected status');

            const docId = data.document_id;
            const docKey = data.document_key;
            // Needed for download/check
            const filename = fd.get('filename');
            const outputFormat = fd.get('output_format') || '';
            const ext = data.ext || '';

            // Polling function
            const poll = () => {
                const checkFd = new FormData();
                checkFd.append('action', 'check');
                checkFd.append('document_id', docId);
                checkFd.append('document_key', docKey);
                checkFd.append('filename', filename);
                checkFd.append('output_format', outputFormat);
                checkFd.append('ext', ext);
                // Also pass target_lang for file suffix generation on backend if needed,
                // though backend might parse from filename or we pass it.
                // Let's pass target_lang just in case.
                checkFd.append('target_lang', tgt);

                fetch('translate.php', { method: 'POST', body: checkFd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.error) {
                            hideSpinner();
                            alert('エラー: ' + res.error);
                            return;
                        }
                        if (res.status === 'done') {
                            updateSpinner(100, '完了');
                            setTimeout(() => {
                                hideSpinner();
                                window.location.href = 'manage.php'; // Reload to show results? Or redirect to download?
                                // Original behavior was redirect to result page "translate.php" HTML output?
                                // Ah, original translate.php outputted HTML at the end.
                                // New translate.php returns JSON.
                                // We should probably reload the page OR show a download link.
                                // Let's reload for now to reflect history/cost updates.
                                alert('翻訳完了');
                                // Optional: prompt download
                                if (res.download_url) {
                                    window.location.href = res.download_url;
                                }
                                setTimeout(() => window.location.reload(), 1000);
                            }, 500);
                        } else {
                            // translating
                            let msg = '翻訳中…';
                            if (res.seconds_remaining) msg += ' (残り約 ' + res.seconds_remaining + '秒)';
                            updateSpinner(50, msg);
                            setTimeout(poll, 1500);
                        }
                    })
                    .catch(err => {
                        hideSpinner();
                        alert('通信エラー: ' + err.message);
                    });
            };

            updateSpinner(10, '送信完了、翻訳開始…');
            poll();
        })
        .catch(err => {
            hideSpinner();
            alert('開始失敗: ' + err.message);
        });
    });
  });
});
</script>
</body>
</html>
