<?php
function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function xml_to_text(string $xml): string {
    $text = preg_replace('/<[^>]+>/', '', $xml);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function estimate_from_zip_entries(ZipArchive $zip, array $entries): int {
    $chars = 0;
    foreach ($entries as $entry) {
        $index = $zip->locateName($entry);
        if ($index === false) {
            continue;
        }
        $content = $zip->getFromIndex($index);
        if ($content !== false) {
            $chars += mb_strlen(xml_to_text($content));
        }
    }
    return $chars;
}

/**
 * Estimate character count of a document.
 *
 * @return array{int,string} [characters, detail]
 */
function estimate_chars(string $path, string $ext): array {
    $ext = strtolower($ext);
    $chars = 0;
    $detail = '';

    if ($ext === 'pdf') {
        $cmd = sprintf('pdftotext -q %s - 2>/dev/null', escapeshellarg($path));
        $text = shell_exec($cmd);
        if (!is_string($text) || $text === '') {
            $tmp = tempnam(sys_get_temp_dir(), 'pdf');
            $cmd = sprintf(
                'qpdf --decrypt %s %s 2>/dev/null && pdftotext -q %s - 2>/dev/null',
                escapeshellarg($path),
                escapeshellarg($tmp),
                escapeshellarg($tmp)
            );
            $text = shell_exec($cmd);
            @unlink($tmp);
        }
        if (is_string($text) && $text !== '') {
            $chars = mb_strlen($text);
            $detail = 'pdf';
        }
    } elseif (in_array($ext, ['docx', 'pptx', 'xlsx'], true)) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $entries = [];
            if ($ext === 'docx') {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (strpos($name, 'word/') === 0 && substr($name, -4) === '.xml') {
                        $entries[] = $name;
                    }
                }
            } elseif ($ext === 'pptx') {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (
                        (strpos($name, 'ppt/slides/') === 0 || strpos($name, 'ppt/notesSlides/') === 0)
                        && substr($name, -4) === '.xml'
                    ) {
                        $entries[] = $name;
                    }
                }
            } elseif ($ext === 'xlsx') {
                $entries = ['xl/sharedStrings.xml'];
            }
            $chars = estimate_from_zip_entries($zip, $entries);
            if ($chars > 0) {
                $detail = $ext;
            }
            $zip->close();
        }
    }

    return [$chars, $detail];
}

