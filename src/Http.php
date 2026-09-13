<?php

namespace Vkrapotkin\WatchdogBot;

final class Http
{
    public static function request(string $url, int $timeout, ?array $payload = null): array
    {
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WatchdogBot/0.1',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }
        curl_exec($curl);
        $result = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'error' => curl_errno($curl), 'body' => $body];
        // Do not log curl_error: Telegram URLs contain the token.
        unset($curl);
        return $result;
    }

    public static function probe(array $config): array
    {
        return self::evaluate(self::request($config['url'], $config['timeout_seconds']), $config['expected_text']);
    }

    public static function evaluate(array $response, string $marker): array
    {
        if ($response['error']) {
            return [false, 'Network/TLS error '.$response['error']];
        }
        if ($response['status'] !== 200) {
            return [false, 'HTTP '.$response['status']];
        }
        if ($marker !== '' && !str_contains($response['body'], $marker)) {
            return [false, 'Expected page text missing'];
        }
        return [true, 'HTTP 200'];
    }

    public static function send(array $config, string $chat, string $message): bool
    {
        $response = self::request('https://api.telegram.org/bot'.$config['token'].'/sendMessage', $config['timeout_seconds'], [
            'chat_id' => $chat, 'text' => $message,
        ]);
        return $response['error'] === 0 && $response['status'] === 200 && (json_decode($response['body'], true)['ok'] ?? false) === true;
    }
}
