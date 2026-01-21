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
$sessionToken = $_SESSION['csrf_token'] ?? '';
$postToken = $_POST['csrf_token'] ?? '';
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    http_response_code(400);
    exit('Invalid CSRF token');
}
$id = trim($_POST['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    exit('Invalid ID');
}

$apiKey = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
if ($apiKey === '' || $apiBase === '') {
    http_response_code(500);
    exit('DeepL API未設定');
}

$ch = curl_init($apiBase . '/glossaries/' . rawurlencode($id));
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'DELETE',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $apiKey],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($res !== false && $code < 400) {
    header('Location: glossary.php?deleted=1');
    exit;
}
header('Location: glossary.php?error=1');
exit;
