<?php

declare(strict_types=1);

require_once __DIR__ . '/ai_settings.php';

function gemini_compare_listing_images(string $sourcePath, string $sourceMime, string $candidatePath, string $candidateMime, array $context): ?array
{
    $settings = gemini_settings();
    if ($settings['api_key'] === '' || !is_file($sourcePath) || !is_file($candidatePath)) return null;
    if (filesize($sourcePath) + filesize($candidatePath) > 18 * 1024 * 1024) return null;

    $prompt = 'You are a strict travel marketplace duplicate-listing reviewer. Compare image A and image B with this listing context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) . '. Return JSON only: {"same_property":true|false,"confidence":0-100,"reason":"short Turkish reason"}. same_property=true only if both images are very likely the same hotel/property/product, not merely similar rooms or stock photos.';
    $body = ['contents' => [['parts' => [
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => $sourceMime, 'data' => base64_encode((string) file_get_contents($sourcePath))]],
        ['inline_data' => ['mime_type' => $candidateMime, 'data' => base64_encode((string) file_get_contents($candidatePath))]],
    ]]], 'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.0]];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($settings['model']) . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $settings['api_key']], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45]);
    $raw = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode((string) $raw, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($status < 200 || $status >= 300 || !is_string($text)) return null;
    $result = json_decode($text, true);
    if (!is_array($result) || !isset($result['same_property'], $result['confidence'])) return null;
    return ['same_property' => (bool) $result['same_property'], 'confidence' => max(0, min(100, (float) $result['confidence'])), 'reason' => mb_substr((string) ($result['reason'] ?? ''), 0, 500)];
}
