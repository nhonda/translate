<?php

class DeepLService {
    private string $apiKey;
    private string $apiBase;

    public function __construct(string $apiKey, string $apiBase) {
        $this->apiKey = $apiKey;
        $this->apiBase = rtrim($apiBase, '/');
    }

    private function request(string $method, string $endpoint, array $params = [], array $headers = []) {
        $url = $this->apiBase . $endpoint;
        $ch = curl_init();
        
        $authHeader = 'Authorization: DeepL-Auth-Key ' . $this->apiKey;
        $headers[] = $authHeader;

        // Mask auth char in logs? We'll leave logging to caller or implement internal logger if needed.
        // For now, basic implementation.

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $params;
            // Note: If $params contains CURLFile, Content-Type is multipart/form-data automatically.
            // If it's a query string, it's x-www-form-urlencoded.
        } else {
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
        }
        $opts[CURLOPT_URL] = $url;

        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            throw new Exception("DeepL API Connection Error: $err");
        }
        if ($code >= 400) {
            $msg = $res;
            $json = json_decode($res, true);
            if (isset($json['message'])) {
                $msg = $json['message'];
            }
            throw new Exception("DeepL API Error ($code): $msg");
        }

        return $res; // Return raw response (string)
    }

    public function translateText(string $text, string $targetLang, string $sourceLang = '', string $glossaryId = ''): array {
        $params = [
            'text' => $text,
            'target_lang' => $targetLang,
        ];
        if ($sourceLang !== '') {
            $params['source_lang'] = $sourceLang;
        }
        if ($glossaryId !== '') {
            $params['glossary_id'] = $glossaryId;
        }

        $res = $this->request('POST', '/translate', $params);
        $data = json_decode($res, true);
        return $data['translations'][0] ?? [];
    }

    public function uploadDocument(string $filePath, string $targetLang, string $sourceLang = '', string $glossaryId = '', string $outputFormat = ''): array {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $params = [
            'file' => new CURLFile($filePath),
            'target_lang' => $targetLang,
        ];
        if ($sourceLang !== '') {
            $params['source_lang'] = $sourceLang;
        }
        if ($glossaryId !== '') {
            $params['glossary_id'] = $glossaryId;
        }
        if ($outputFormat !== '') {
            $params['output_format'] = $outputFormat;
        }

        $res = $this->request('POST', '/document', $params);
        return json_decode($res, true);
    }

    public function checkStatus(string $documentId, string $documentKey): array {
        $params = [
            'document_key' => $documentKey,
        ];
        $res = $this->request('POST', "/document/" . rawurlencode($documentId), $params);
        return json_decode($res, true);
    }

    public function downloadResult(string $documentId, string $documentKey): string {
        $params = [
            'document_key' => $documentKey,
        ];
        return $this->request('POST', "/document/" . rawurlencode($documentId) . "/result", $params);
    }
    
    public function listGlossaries(): array {
        $res = $this->request('GET', '/glossaries');
        $data = json_decode($res, true);
        return $data['glossaries'] ?? [];
    }

    public function createGlossary(string $name, string $sourceLang, string $targetLang, string $entriesTsv): array {
         $params = [
            'name' => $name,
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'entries' => $entriesTsv,
            'entries_format' => 'tsv',
        ];
        $res = $this->request('POST', '/glossaries', $params);
        return json_decode($res, true);
    }
    
    public function deleteGlossary(string $glossaryId): void {
        $this->request('DELETE', "/glossaries/" . rawurlencode($glossaryId));
    }
}
