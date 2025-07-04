<?php
require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

/* DeepL APIキー読み込み */
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
define('DEEPL_KEY', $_ENV['DEEPL_AUTH_KEY'] ?? '');

/* パラメータ取得 */
$filename = $_POST['filename'] ?? '';
$fmt      = $_POST['out_fmt']  ?? '';             // pdf | docx | xlsx
if (!in_array($fmt, ['pdf','docx','xlsx'], true)) die('不正な形式');

$src = __DIR__ . '/uploads/' . basename($filename);
if (!is_file($src)) die('元ファイルが見つかりません');

$ext    = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$base   = pathinfo($filename, PATHINFO_FILENAME);
$dlDir  = __DIR__ . '/downloads';
if (!is_dir($dlDir)) mkdir($dlDir, 0777, true);

/*====================================================================
  A) .txt  →  DeepL Text-API  →  PDFまたはDOCX
====================================================================*/
if ($ext === 'txt') {
    $plain = file_get_contents($src);
    if (trim($plain) === '') die('翻訳対象が空です');

    // DeepL Text-API (4500字ごと)
    $chunks     = mb_str_split($plain, 4500);
    $translated = '';
    foreach ($chunks as $c) {
        $post = http_build_query([
            'auth_key'    => DEEPL_KEY,
            'text'        => $c,
            'target_lang' => 'JA',
        ]);
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>'Content-Type: application/x-www-form-urlencoded',
            'content'=>$post
        ]]);
        $res = json_decode(file_get_contents(
               'https://api.deepl.com/v2/translate', false, $ctx), true);
        $translated .= $res['translations'][0]['text'] ?? '[翻訳失敗]';
    }

    /* PDF or DOCX で保存 */
    if ($fmt === 'pdf') {
        // --- PDF生成 ---
        $extraFontDir = __DIR__ . '/fonts';                 // ipaexg.ttf を置いた場所
        $fontDir      = array_merge(
            (new ConfigVariables())->getDefaults()['fontDir'],
            [ $extraFontDir ]
        );
        $fontData     = (new FontVariables())->getDefaults()['fontdata'] + [
            'ipaexg' => [ 'R' => 'ipaexg.ttf' ],            // Regular ウェイト
        ];
        $mpdf = new Mpdf([
            'fontDir'          => $fontDir,
            'fontdata'         => $fontData,
            'default_font'     => 'ipaexg',
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'tempDir'          => '/tmp',
            'format'           => 'A4',
        ]);
        $html = '<pre style="font-family: ipaexg; font-size: 12px; white-space: pre-wrap;">'
              . htmlspecialchars($translated) . '</pre>';
        $mpdf->WriteHTML($html);
        $save = $base . '_jp.pdf';
        $mpdf->Output($dlDir . '/' . $save, \Mpdf\Output\Destination::FILE);
    } else {
        // --- DOCX生成 ---
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        // IPAexゴシックを使う場合は別途インストール・登録が必要
        $section->addText($translated, ['name'=>'IPAexGothic', 'size'=>12]);
        $save = $base . '_jp.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($dlDir . '/' . $save);
    }

    header('Location: downloads.php?done=' . urlencode($save));
    exit;
}

/*====================================================================
  B) .pdf / .docx / .xlsx  →  DeepL Document-API
====================================================================*/
// DeepL API: PDFアップロード時はPDFしか出力不可
if ($ext === 'pdf' && $fmt !== 'pdf') {
    die('DeepL API仕様上、PDF→他形式はサポートされていません。PDFでのみ出力可能です。');
}
// DeepL API: XLSXアップロード時はXLSXしか出力不可
if ($ext === 'xlsx' && $fmt !== 'xlsx') {
    die('DeepL API仕様上、XLSX→他形式はサポートされていません。XLSXでのみ出力可能です。');
}

$up = curl_init('https://api.deepl.com/v2/document');
curl_setopt_array($up, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'file'          => new CURLFile($src),
        'auth_key'      => DEEPL_KEY,
        'target_lang'   => 'JA',
        'output_format' => $fmt,
    ]
]);
$resp = curl_exec($up);
$code = curl_getinfo($up, CURLINFO_RESPONSE_CODE);
curl_close($up);

if ($code !== 200) {
    error_log("Document-API upload ERROR $code : $resp");
    die('アップロード失敗');
}
$resp = json_decode($resp, true);
[$id, $key] = [$resp['document_id'], $resp['document_key']];

for ($i = 0; $i < 300; $i++) {
    sleep(4);
    $resp = file_get_contents(
        "https://api.deepl.com/v2/document/$id?auth_key=" . DEEPL_KEY . "&document_key=$key"
    );
    $stat = json_decode($resp, true);
    if (!is_array($stat) || !isset($stat['status'])) {
        die('翻訳エラー: API応答が不正です');
    }
    if ($stat['status'] === 'done') break;
    if ($stat['status'] === 'error') die('翻訳エラー: ' . $stat['message']);
}
if ($stat['status']!=='done') die('タイムアウト');

/* ダウンロード */
$tmp = tempnam($dlDir,'tmp_');
$in  = fopen(
    "https://api.deepl.com/v2/document/$id/result?auth_key=".DEEPL_KEY."&document_key=$key",
    'rb');
$out = fopen($tmp,'wb'); stream_copy_to_stream($in,$out);
fclose($in); fclose($out);

// ファイル名拡張子
$actual_ext = $fmt;
if ($ext === 'pdf') $actual_ext = 'pdf';
if ($ext === 'xlsx') $actual_ext = 'xlsx';
if ($ext === 'docx') $actual_ext = $fmt;

$save = $base . '_jp.' . $actual_ext;
rename($tmp, "$dlDir/$save");
header('Location: downloads.php?done=' . urlencode($save));
exit;
