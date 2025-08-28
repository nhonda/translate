<?php
require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;

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
if (!is_dir($dlDir) && !mkdir($dlDir, 0755, true)) {
    error_log("Failed to create download directory: $dlDir");
    http_response_code(500);
    die('ダウンロードディレクトリの作成に失敗しました');
}

/*====================================================================
  A) .txt  →  DeepL Text-API  →  PDFまたはDOCX
====================================================================*/
if ($ext === 'txt') {
    $plain = file_get_contents($src);
    if ($plain === false) {
        error_log("Failed to read source file: $src");
        http_response_code(500);
        die('元ファイルの読み込みに失敗しました');
    }
    if (trim($plain) === '') die('翻訳対象が空です');

    // DeepL Text-API (4500字ごと)
    $chunks     = mb_str_split($plain, 4500);
    $translated = '';
    foreach ($chunks as $c) {
        $post = http_build_query([
            'auth_key'    => DEEPL_KEY,
            'text'        => $c,
            'source_lang' => 'EN',
            'target_lang' => 'JA',
        ]);
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>'Content-Type: application/x-www-form-urlencoded',
            'content'=>$post
        ]]);
        $resStr = file_get_contents(
            'https://api.deepl.com/v2/translate', false, $ctx);
        if ($resStr === false) {
            error_log('Text-API request failed');
            http_response_code(500);
            die('翻訳に失敗しました');
        }
        $res = json_decode($resStr, true);
        if (!isset($res['translations'][0]['text'])) {
            error_log('Text-API response error: ' . ($res['message'] ?? $resStr));
            die('翻訳に失敗しました');
        }
        $translated .= $res['translations'][0]['text'];
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
  B) .xlsx  →  DeepL Text-API
====================================================================*/
if ($ext === 'xlsx') {
    if ($fmt !== 'xlsx') {
        die('DeepL API仕様上、XLSX→他形式はサポートされていません。XLSXでのみ出力可能です。');
    }
    // DeepL Text API helper: send array of texts with exponential backoff retry
    $deepl = function(array $texts) {
        if (empty($texts)) return [];
        $query = http_build_query([
            'auth_key'    => DEEPL_KEY,
            'source_lang' => 'EN',
            'target_lang' => 'JA',
        ], '', '&', PHP_QUERY_RFC3986);
        foreach ($texts as $t) {
            $query .= '&text=' . urlencode($t);
        }
        $delay = 1;
        for ($i = 0; $i < 5; $i++) {
            $ch = curl_init('https://api.deepl.com/v2/translate');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $query,
            ]);
            $resStr = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($resStr !== false && $code === 200) {
                $res = json_decode($resStr, true);
                if (isset($res['translations']) && count($res['translations']) === count($texts)) {
                    return array_column($res['translations'], 'text');
                }
                error_log('Text-API response error: ' . ($res['message'] ?? $resStr));
                break;
            }
            if (!in_array($code, [429, 503], true)) break;
            sleep($delay);
            $delay *= 2;
        }
        http_response_code(500);
        die('翻訳に失敗しました');
    };

    $spreadsheet = SpreadsheetIOFactory::load($src);
    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $batchCells = [];
        $batchTexts = [];
        $batchLen   = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $val = $cell->getValue();
                if (is_string($val) && trim($val) !== '') {
                    $len = mb_strlen($val, 'UTF-8');
                    if ($batchCells && $batchLen + $len > 30000) {
                        $translated = $deepl($batchTexts);
                        foreach ($batchCells as $idx => $c) {
                            $c->setValue($translated[$idx]);
                        }
                        $batchCells = [];
                        $batchTexts = [];
                        $batchLen   = 0;
                    }
                    $batchCells[] = $cell;
                    $batchTexts[] = $val;
                    $batchLen    += $len;
                } else {
                    if ($batchCells) {
                        $translated = $deepl($batchTexts);
                        foreach ($batchCells as $idx => $c) {
                            $c->setValue($translated[$idx]);
                        }
                        $batchCells = [];
                        $batchTexts = [];
                        $batchLen   = 0;
                    }
                }
            }
        }
        if ($batchCells) {
            $translated = $deepl($batchTexts);
            foreach ($batchCells as $idx => $c) {
                $c->setValue($translated[$idx]);
            }
        }
    }
    $save = $base . '_jp.xlsx';
    $writer = SpreadsheetIOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($dlDir . '/' . $save);
    header('Location: downloads.php?done=' . urlencode($save));
    exit;
}

/*====================================================================
  C) .pdf / .docx  →  DeepL Document-API
====================================================================*/
// DeepL API: PDFアップロード時はPDFしか出力不可
if ($ext === 'pdf' && $fmt !== 'pdf') {
    die('DeepL API仕様上、PDF→他形式はサポートされていません。PDFでのみ出力可能です。');
}

$up = curl_init('https://api.deepl.com/v2/document');
$fileCurl = new CURLFile($src);
curl_setopt_array($up, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'file'          => $fileCurl,
        'auth_key'      => DEEPL_KEY,
        'source_lang'   => 'EN',
        'target_lang'   => 'JA',
        'output_format' => $fmt,
    ]
]);
$resp = curl_exec($up);
$code = curl_getinfo($up, CURLINFO_RESPONSE_CODE);
curl_close($up);

if ($code !== 200) {
    error_log("Document-API upload ERROR $code : $resp");
    die('翻訳に失敗しました');
}
$respArr = json_decode($resp, true);
if (!isset($respArr['document_id'], $respArr['document_key'])) {
    error_log('Document-API upload error: ' . ($respArr['message'] ?? $resp));
    die('翻訳に失敗しました');
}
[$id, $key] = [$respArr['document_id'], $respArr['document_key']];

for ($i = 0; $i < 300; $i++) {
    sleep(4);
    $resp = file_get_contents(
        "https://api.deepl.com/v2/document/$id?auth_key=" . DEEPL_KEY . "&document_key=$key"
    );
    if ($resp === false) {
        error_log('Document-API status request failed');
        http_response_code(500);
        die('翻訳に失敗しました');
    }
    $stat = json_decode($resp, true);
    if (!is_array($stat) || !isset($stat['status'])) {
        error_log('Document-API status parse error: ' . $resp);
        http_response_code(500);
        die('翻訳に失敗しました');
    }
    if ($stat['status'] === 'done') break;
    if ($stat['status'] === 'error') {
        error_log('Document-API status error: ' . ($stat['message'] ?? 'unknown'));
        http_response_code(500);
        die('翻訳に失敗しました');
    }
}
if ($stat['status']!=='done') {
    error_log('Document-API timeout');
    http_response_code(500);
    die('翻訳に失敗しました');
}

/* ダウンロード */
$tmp = tempnam($dlDir,'tmp_');
$in  = fopen(
    "https://api.deepl.com/v2/document/$id/result?auth_key=".DEEPL_KEY."&document_key=$key",
    'rb');
if ($in === false) {
    error_log('Document-API result download failed');
    die('翻訳に失敗しました');
}
$out = fopen($tmp,'wb');
stream_copy_to_stream($in,$out);
fclose($in);
fclose($out);

// ファイル名拡張子
$actual_ext = $fmt;
if ($ext === 'pdf') $actual_ext = 'pdf';
if ($ext === 'docx') $actual_ext = $fmt;

$save = $base . '_jp.' . $actual_ext;
rename($tmp, "$dlDir/$save");
header('Location: downloads.php?done=' . urlencode($save));
exit;
