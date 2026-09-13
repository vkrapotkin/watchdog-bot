<?php

namespace Vkrapotkin\WatchdogBot;

use InvalidArgumentException;

final class Settings
{
    public static function validate(array $config, bool $delivery = true): array
    {
        foreach (['id', 'name', 'url'] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                throw new InvalidArgumentException("Missing setting: $key");
            }
        }
        $url = parse_url($config['url']);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])) {
            throw new InvalidArgumentException('Expected an HTTPS URL without credentials');
        }
        foreach (['retry_seconds' => 30, 'timeout_seconds' => 15] as $key => $default) {
            $config[$key] ??= $default;
            if (!is_int($config[$key]) || $config[$key] < 1 || $config[$key] > 120) {
                throw new InvalidArgumentException("Invalid duration: $key");
            }
        }
        $config['expected_text'] ??= '';
        if (!is_string($config['expected_text'])) {
            throw new InvalidArgumentException('expected_text must be a string');
        }
        $config['chat_ids'] ??= [];
        if (!is_array($config['chat_ids']) || !array_is_list($config['chat_ids'])) {
            throw new InvalidArgumentException('chat_ids must be a list');
        }
        foreach ($config['chat_ids'] as $chat) {
            if (!is_string($chat) || !preg_match('/^-?[1-9][0-9]*$/D', $chat)) {
                throw new InvalidArgumentException('Chat IDs must be numeric strings');
            }
        }
        if (count($config['chat_ids']) !== count(array_unique($config['chat_ids']))) {
            throw new InvalidArgumentException('Duplicate chat IDs');
        }
        if ($delivery && (!$config['chat_ids'] || !is_string($config['token'] ?? null) || !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $config['token']))) {
            throw new InvalidArgumentException('Configure Telegram token and recipients');
        }
        return $config;
    }
}
