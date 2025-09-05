<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/common.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
if (file_exists(__DIR__ . '/.env')) {
    $dotenv->load();
}

function env_non_empty(string $key): string {
    $candidates = [];
    if (array_key_exists($key, $_ENV)) {
        $candidates[] = $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        $candidates[] = $_SERVER[$key];
    }
    $getenv = getenv($key);
    if ($getenv !== false) {
        $candidates[] = $getenv;
    }
    foreach ($candidates as $v) {
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '') {
                return $t;
            }
        }
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$id = trim($_POST['id'] ?? '');
$name = trim($_POST['name'] ?? '');
$terms = $_POST['terms'] ?? [];
$termsRev = $_POST['terms_rev'] ?? [];
$reverseId = trim($_POST['reverse_id'] ?? '');
if ($id === '') {
    http_response_code(400);
    exit('Invalid ID');
}

$rows = [];
if (is_array($terms)) {
    foreach ($terms as $pair) {
        $src = trim($pair['source'] ?? '');
        $dst = trim($pair['target'] ?? '');
        if ($src !== '' && $dst !== '') {
            $rows[] = $src . "\t" . $dst;
        }
    }
}
$rowsRev = [];
if (is_array($termsRev)) {
    foreach ($termsRev as $pair) {
        $src = trim($pair['source'] ?? '');
        $dst = trim($pair['target'] ?? '');
        if ($src !== '' && $dst !== '') {
            $rowsRev[] = $src . "\t" . $dst;
        }
    }
}

$errors = [];
if ($name === '') {
    $errors[] = '名前を入力してください';
}
if (empty($rows) && empty($rowsRev)) {
    $errors[] = '用語を入力してください（どちらか一方でも可）';
}

$apiKey = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
if ($apiKey === '' || $apiBase === '') {
    $errors[] = 'DeepL APIの設定が必要です';
}

if (empty($errors)) {
    // 1) 現在の言語設定を取得
    $ch = curl_init($apiBase . '/glossaries/' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) {
        $errors[] = '既存用語集の取得に失敗しました';
    } else {
        $info = json_decode($res, true) ?: [];
        $sourceLang = (string)($info['source_lang'] ?? 'en');
        $targetLang = (string)($info['target_lang'] ?? 'ja');

        $replaceMap = [];
        // 2) 主方向を再作成
        if (!empty($rows)) {
            $payload = [
                'name' => $name,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'entries' => implode("\n", $rows),
                'entries_format' => 'tsv',
            ];
            $ch = curl_init($apiBase . '/glossaries');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
            ]);
            $resCreate = curl_exec($ch);
            $codeCreate = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resCreate === false || $codeCreate >= 400) {
                $errors[] = '用語集（主方向）の再作成に失敗しました';
            } else {
                $created = json_decode($resCreate, true);
                $newIdMain = is_array($created) ? (string)($created['glossary_id'] ?? '') : '';
                if ($newIdMain !== '') {
                    $replaceMap[] = ['old' => $id, 'new' => $newIdMain];
                }
                // 旧を削除
                $ch = curl_init($apiBase . '/glossaries/' . rawurlencode($id));
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'DELETE',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 60,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        // 3) 逆方向を再作成（任意）
        if (!empty($rowsRev)) {
            $revSource = $targetLang; // 逆方向
            $revTarget = $sourceLang;
            $payload = [
                'name' => $name,
                'source_lang' => $revSource,
                'target_lang' => $revTarget,
                'entries' => implode("\n", $rowsRev),
                'entries_format' => 'tsv',
            ];
            $ch = curl_init($apiBase . '/glossaries');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
            ]);
            $resCreate2 = curl_exec($ch);
            $codeCreate2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resCreate2 === false || $codeCreate2 >= 400) {
                $errors[] = '用語集（逆方向）の再作成に失敗しました';
            } else {
                $created2 = json_decode($resCreate2, true);
                $newIdRev = is_array($created2) ? (string)($created2['glossary_id'] ?? '') : '';
                if ($newIdRev !== '' && $reverseId !== '') {
                    $replaceMap[] = ['old' => $reverseId, 'new' => $newIdRev];
                }
                if ($reverseId !== '') {
                    $ch = curl_init($apiBase . '/glossaries/' . rawurlencode($reverseId));
                    curl_setopt_array($ch, [
                        CURLOPT_CUSTOMREQUEST => 'DELETE',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
                        CURLOPT_CONNECTTIMEOUT => 15,
                        CURLOPT_TIMEOUT => 60,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }
        }
        if (!empty($replaceMap)) {
            $_SESSION['glossary_replace_map'] = $replaceMap;
        }
    }
}

if (empty($errors)) {
    header('Location: glossary.php?updated=1');
    exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>用語集更新エラー</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
  <h1>用語集更新エラー</h1>
  <nav><a href="glossary.php">一覧に戻る</a></nav>
</header>
<main>
<div class="card">
  <?php foreach ($errors as $e): ?>
    <p style="color:red;"><?= h($e) ?></p>
  <?php endforeach; ?>
  <p><a href="glossary_edit.php?id=<?= h($id ?? '') ?>">編集に戻る</a></p>
</div>
</main>
</body>
</html>
