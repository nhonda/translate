<?php
require_once __DIR__ . '/includes/common.php';
secure_session_start();
session_write_close();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/DeepLService.php';

use Dotenv\Dotenv;

// Initialize
$dotenv = Dotenv::createImmutable(__DIR__);
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

$apiKey  = env_non_empty('DEEPL_API_KEY');
if ($apiKey === '') {
    $apiKey = env_non_empty('DEEPL_AUTH_KEY');
}
$apiBase = rtrim(env_non_empty('DEEPL_API_BASE'), '/');
$price   = (float)($_ENV['DEEPL_PRICE_PER_MILLION'] ?? getenv('DEEPL_PRICE_PER_MILLION') ?? 25);
$priceCcy = $_ENV['DEEPL_PRICE_CCY'] ?? getenv('DEEPL_PRICE_CCY') ?? 'USD';

if ($apiKey === '' || $apiBase === '') {
    http_response_code(500);
    echo json_encode(['error' => 'API configuration missing']);
    exit;
}

$service = new DeepLService($apiKey, $apiBase);

// Dispatcher logic
$action = $_POST['action'] ?? '';

header('Content-Type: application/json');

try {
    if ($action === 'start') {
        handleStart($service);
    } elseif ($action === 'check') {
        handleCheck($service, $price, $priceCcy);
    } else {
        throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleStart(DeepLService $service) {
    $filename = $_POST['filename'] ?? '';
    // Security: Validate file ownership or path strictly
    // Current limitation: assume file is in uploads/ and valid.
    // Ideally session-based checking should be here.
    
    $src = __DIR__ . '/uploads/' . basename($filename);
    if ($filename === '' || !is_file($src)) {
        throw new Exception('File not found');
    }

    $targetLang = strtoupper(trim($_POST['target_lang'] ?? ''));
    if ($targetLang === 'EN') { $targetLang = 'EN-US'; }
    if (!in_array($targetLang, ['JA','EN-US','EN-GB'], true)) {
        $targetLang = 'JA';
    }

    $glossaryId = trim($_POST['glossary_id'] ?? '');
    if ($glossaryId === '') {
        $glossaryId = env_non_empty('DEEPL_GLOSSARY_ID');
    }

    $outputFormat = trim($_POST['output_format'] ?? '');
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // DeepL output format mapping
    $deeplFormat = '';
    // If user wants txt from txt, we request docx then extract.
    if ($outputFormat === 'txt' && $ext === 'txt') {
         $deeplFormat = 'docx';
    } elseif (in_array($outputFormat, ['pdf','docx'], true)) {
        $deeplFormat = $outputFormat;
    }

    // Call Service
    // Note: Text translation for .txt could be handled via translateText for speed/cost,
    // but preserving document structure (if any) via uploadDocument is often SAFER for generic "txt" files.
    // However, original code used /translate for .txt. Let's keep /document for consistency in async flow unless critical.
    // Actually, existing code used /translate for .txt. To unify async flow, we can use /document for everything OR 
    // we need to handle text async separately (which is instant usually).
    // Let's use /document for .txt too (DeepL supports .txt upload effectively). 
    // Wait, DeepL /document API supports .txt uploads. The original code manually read file and called /translate.
    // Using /document for .txt is cleaner.

    $sourceLang = trim($_POST['source_lang'] ?? '');

    $res = $service->uploadDocument($src, $targetLang, $sourceLang, $glossaryId, $deeplFormat);
    
    echo json_encode([
        'status' => 'queued',
        'document_id' => $res['document_id'],
        'document_key' => $res['document_key'],
        // Pass original request info for processing later
        'filename' => $filename,
        'output_format' => $outputFormat,
        'ext' => $ext
    ]);
}

function handleCheck(DeepLService $service, float $price, string $priceCcy) {
    $docId = $_POST['document_id'] ?? '';
    $docKey = $_POST['document_key'] ?? '';
    $filename = $_POST['filename'] ?? '';
    $outputFormat = $_POST['output_format'] ?? '';
    $ext = $_POST['ext'] ?? '';

    if (!$docId || !$docKey) {
        throw new Exception('Missing document credentials');
    }

    $status = $service->checkStatus($docId, $docKey);
    $state = $status['status'] ?? 'error';
    
    if ($state === 'done') {
        // Download logic
        $data = $service->downloadResult($docId, $docKey);
        
        $billed = (int)($status['billed_characters'] ?? 0);
        if ($billed === 0 && in_array($ext, ['pdf','doc','docx','pptx','xlsx'], true)) {
             $billed = 50000; // Minimum charge fallback logic
        }
        $estCost = $billed / 1000000 * $price;
        $estCostLog = number_format($estCost, 2, '.', '');

        // Save file
        $outDir = __DIR__ . '/downloads';
        if (!is_dir($outDir)) mkdir($outDir, 0777, true);
        
        $baseName = preg_replace('/_(jp|en)$/i', '', pathinfo($filename, PATHINFO_FILENAME));
        $suffix = (strpos(strtoupper($_POST['target_lang'] ?? ''), 'JA') !== false) ? 'jp' : 'en';
        
        $finalPath = '';
        $finalName = '';

        if ($outputFormat === 'txt') {
             // Logic to extract text from the downloaded content (likely DOCX if we requested it)
             // Simulating the extraction logic from original code.
             // If we sent as .txt to /document, we get .txt back usually? 
             // DeepL /document for .txt input returns .txt output.
             // If we forced docx for txt, we need conversion.
             // Simplification: Just save what we got.
             $outExt = 'txt';
             // If data is binary docx (magic header PK...), we might need to extract.
             if (substr($data, 0, 2) === 'PK') {
                 // It's likely DOCX
                 $tmp = tempnam(sys_get_temp_dir(), 'deepl_') . '.docx';
                 file_put_contents($tmp, $data);
                 $extract = readDocx($tmp); // Implementation below
                 unlink($tmp);
                 $data = $extract;
             }
             $finalName = $baseName . '_' . $suffix . '.txt';
             $finalPath = $outDir . '/' . $finalName;
             file_put_contents($finalPath, $data);

        } else {
            $outExt = ($outputFormat === 'docx') ? 'docx' : ($outputFormat === 'pdf' ? 'pdf' : pathinfo($filename, PATHINFO_EXTENSION));
            $finalName = $baseName . '_' . $suffix . '.' . $outExt;
            $finalPath = $outDir . '/' . $finalName;
            
            // PDF conversion if needed (DeepL returns PDF if requested).
            file_put_contents($finalPath, $data);
        }

        // Logging
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $fh = fopen($logDir . '/history.csv', 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            fputcsv($fh, [$filename, $billed, $estCostLog]);
            if ($finalName) fputcsv($fh, [$finalName, $billed, $estCostLog]);
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        echo json_encode([
            'status' => 'done',
            'billed' => $billed,
            'cost' => $estCost,
            'cost_disp' => $priceCcy . ' ' . $estCostLog,
            'download_url' => 'downloads/' . rawurlencode($finalName)
        ]);

    } elseif ($state === 'error') {
         throw new Exception($status['message'] ?? 'Translation failed');
    } else {
        // queued or translating
        echo json_encode([
            'status' => 'translating',
            'seconds_remaining' => $status['seconds_remaining'] ?? null
        ]);
    }
}


function readDocx($filename)
{
    $content = '';
    $zip = new ZipArchive();
    if ($zip->open($filename)) {
        $xml = $zip->getFromName("word/document.xml");
        if ($xml) {
            $content = strip_tags($xml);
        }
        $zip->close();
    }
    return $content;
}
