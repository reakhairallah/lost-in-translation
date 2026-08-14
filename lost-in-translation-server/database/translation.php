<?php

function callMyMemory(string $text, string $sourceLang, string $targetLang): array {
    $params = [
        'q' => $text,
        'langpair' => "$sourceLang|$targetLang",
    ];
    $url = 'https://api.mymemory.translated.net/get?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlErrno !== 0) {
        return ['success' => false, 'text' => '', 'error' => "curl transport error: $curlError"];
    }

    $decoded = json_decode($response, true);

    if ($decoded === null) {
        return ['success' => false, 'text' => '', 'error' => 'response was not valid JSON'];
    }

    if (!isset($decoded['responseStatus'])) {
        $detail = $decoded['responseDetails'] ?? 'unknown error';
        return ['success' => false, 'text' => '', 'error' => "MyMemory responseStatus failure: $detail"];
    }

    $text = $decoded['responseData']['translatedText'] ?? '';

    if ($text === '') {
        return ['success' => false, 'text' => '', 'error' => 'MyMemory returned an empty translation'];
    }

    return ['success' => true, 'text' => $text, 'error' => ''];
}

?>
