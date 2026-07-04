<?php
/**
 * Auto-translate custom marquee lines (cached on disk).
 *
 * Uses the free MyMemory API when outbound HTTP is available.
 * Falls back to the source-language text when translation fails.
 */

require_once __DIR__ . '/language-support.php';

/**
 * @return string
 */
function marquee_cache_directory() {
    $dir = __DIR__ . '/../cache/marquee';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * @param array $lines
 * @return string
 */
function marquee_source_fingerprint(array $lines) {
    $normalized = array();

    foreach ($lines as $line) {
        $normalized[] = trim((string) $line);
    }

    return md5(implode("\x1e", $normalized));
}

/**
 * @param string $sourceLang
 * @param string $targetLang
 * @param string $fingerprint
 * @return string
 */
function marquee_translation_cache_file($sourceLang, $targetLang, $fingerprint) {
    $safe = preg_replace('/[^a-z0-9_-]/i', '', $sourceLang . '_' . $targetLang . '_' . $fingerprint);

    return marquee_cache_directory() . '/' . $safe . '.json';
}

/**
 * @param string $sourceLang
 * @param string $targetLang
 * @param string $fingerprint
 * @return array|null
 */
function marquee_read_translation_cache($sourceLang, $targetLang, $fingerprint) {
    $path = marquee_translation_cache_file($sourceLang, $targetLang, $fingerprint);

    if (!is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['lines']) || !is_array($data['lines'])) {
        return null;
    }

    return $data['lines'];
}

/**
 * @param string $sourceLang
 * @param string $targetLang
 * @param string $fingerprint
 * @param array $lines
 * @return void
 */
function marquee_write_translation_cache($sourceLang, $targetLang, $fingerprint, array $lines) {
    $path = marquee_translation_cache_file($sourceLang, $targetLang, $fingerprint);
    $payload = json_encode(array('lines' => array_values($lines)), JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return;
    }

    @file_put_contents($path, $payload, LOCK_EX);
}

/**
 * @param string $text
 * @param string $from
 * @param string $to
 * @param string $contactEmail
 * @return string|null
 */
function marquee_fetch_mymemory_translation($text, $from, $to, $contactEmail = '') {
    $query = array(
        'q' => $text,
        'langpair' => $from . '|' . $to,
    );

    if ($contactEmail !== '') {
        $query['de'] = $contactEmail;
    }

    $url = 'https://api.mymemory.translated.net/get?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 6,
            'header' => "User-Agent: RYSEN-MONITOR-Marquee/1.0\r\n",
        ),
    ));

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['responseData']['translatedText'])) {
        return null;
    }

    $translated = trim((string) $data['responseData']['translatedText']);
    if ($translated === '' || stripos($translated, 'MYMEMORY WARNING') !== false) {
        return null;
    }

    return $translated;
}

/**
 * @param string $text
 * @param string $from
 * @param string $to
 * @param string $contactEmail
 * @return string
 */
function marquee_translate_text($text, $from, $to, $contactEmail = '') {
    $text = trim((string) $text);
    if ($text === '' || $from === $to) {
        return $text;
    }

    $translated = marquee_fetch_mymemory_translation($text, $from, $to, $contactEmail);

    return $translated !== null ? $translated : $text;
}

/**
 * @param array $lines Plain-text source lines
 * @param string $from Source language code
 * @param string $to Target language code
 * @param string $contactEmail Optional MyMemory contact email for higher quota
 * @return array
 */
function marquee_translate_lines(array $lines, $from, $to, $contactEmail = '') {
    $from = map_language_code($from);
    $to = map_language_code($to);

    if ($from === $to) {
        return $lines;
    }

    $supported = get_supported_language_codes();
    if (!in_array($from, $supported, true) || !in_array($to, $supported, true)) {
        return $lines;
    }

    $fingerprint = marquee_source_fingerprint($lines);
    $cached = marquee_read_translation_cache($from, $to, $fingerprint);
    if ($cached !== null && count($cached) === count($lines)) {
        return $cached;
    }

    $translated = array();
    foreach ($lines as $line) {
        if (!is_string($line) || trim($line) === '') {
            continue;
        }
        $translated[] = marquee_translate_text($line, $from, $to, $contactEmail);
    }

    if (count($translated) === count($lines)) {
        marquee_write_translation_cache($from, $to, $fingerprint, $translated);
    }

    return $translated;
}

?>
