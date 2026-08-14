<?php

define('CLAUDE_API_KEY', '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');

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

    if (!isset($decoded['responseStatus']) || $decoded['responseStatus'] != 200) {
        $detail = 'unknown error';
        if (isset($decoded['responseDetails'])) {
            $detail = $decoded['responseDetails'];
        }
        return ['success' => false, 'text' => '', 'error' => "MyMemory responseStatus failure: $detail"];
    }

    $translatedText = '';
    if (isset($decoded['responseData']['translatedText'])) {
        $translatedText = $decoded['responseData']['translatedText'];
    }

    if ($translatedText === '') {
        return ['success' => false, 'text' => '', 'error' => 'MyMemory returned an empty translation'];
    }

    return ['success' => true, 'text' => $translatedText, 'error' => ''];
}

function callClaudeTranslate(string $text, string $sourceLang, string $targetLang): array {
    $prompt = "Translate the following text from $sourceLang to $targetLang. Reply with ONLY the translated text, nothing else, no quotes, no explanation.\n\nText: $text";

    $requestBody = [
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

    if ($httpCode != 200) {
        $detail = 'unknown error';
        if (isset($decoded['error']['message'])) {
            $detail = $decoded['error']['message'];
        }
        return ['success' => false, 'text' => '', 'error' => "Claude API HTTP $httpCode: $detail"];
    }

    $translatedText = '';
    if (isset($decoded['content'][0]['text'])) {
        $translatedText = $decoded['content'][0]['text'];
    }

    if ($translatedText === '') {
        return ['success' => false, 'text' => '', 'error' => 'Claude returned an empty translation'];
    }

    return ['success' => true, 'text' => $translatedText, 'error' => ''];
}

function callProviderOnce(string $provider, string $text, string $sourceLang, string $targetLang): array {
    if ($provider === 'claude') {
        return callClaudeTranslate($text, $sourceLang, $targetLang);
    }

    if ($provider === 'mymemory') {
        return callMyMemory($text, $sourceLang, $targetLang);
    }

    return ['success' => false, 'text' => '', 'error' => "unknown provider: $provider"];
}

function translate(string $text, string $sourceLang, string $targetLang, string $provider = 'mymemory'): array {
    $result = callProviderOnce($provider, $text, $sourceLang, $targetLang);

    if ($result['success'] === false) {
        $result = callProviderOnce($provider, $text, $sourceLang, $targetLang);
    }

    return $result;
}
