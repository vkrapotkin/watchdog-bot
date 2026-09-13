<?php

namespace Vkrapotkin\WatchdogBot;

use RuntimeException;

final class Monitor
{
    public function __construct(
        private array $config,
        private string $directory,
        private mixed $probe = [Http::class, 'probe'],
        private mixed $send = [Http::class, 'send'],
        private mixed $wait = 'sleep',
        private mixed $clock = 'time',
    ) {}

    public function run(): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create state directory');
        }
        $lock = fopen($this->directory.'/run.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['skipped' => true];
            }
            $path = $this->directory.'/state.json';
            $state = is_file($path) ? JsonFile::read($path) : [
                'site' => $this->config['id'], 'down' => null, 'since' => null, 'queue' => [],
            ];
            if (($state['site'] ?? null) !== $this->config['id'] || !array_key_exists('down', $state) ||
                (!is_bool($state['down']) && $state['down'] !== null) || !is_array($state['queue'] ?? null)) {
                throw new RuntimeException('Invalid state or directory belongs to another site');
            }
            [$healthy, $detail] = ($this->probe)($this->config);
            if (!$healthy) {
                ($this->wait)($this->config['retry_seconds']);
                [$healthy, $detail] = ($this->probe)($this->config);
            }
            $now = ($this->clock)();
            $down = !$healthy;
            if (($state['down'] === null && $down) || ($state['down'] !== null && $state['down'] !== $down)) {
                $text = ($down ? 'Сайт недоступен: ' : 'Сайт восстановлен: ').$this->config['name']."\n".$this->config['url']."\n".$detail."\n".gmdate('Y-m-d H:i:s', $now).' UTC';
                if (!$down) {
                    $text .= "\nС момента обнаружения: ".max(0, $now - $state['since']).' сек.';
                }
                foreach ($this->config['chat_ids'] as $chat) {
                    $state['queue'][] = ['chat' => $chat, 'text' => $text];
                }
                $state['since'] = $now;
            }
            $state['down'] = $down;
            $state['checked_at'] = $now;
            JsonFile::write($path, $state);
            $blocked = [];
            foreach (array_keys($state['queue']) as $index) {
                $item = $state['queue'][$index];
                if (!in_array($item['chat'], $this->config['chat_ids'], true)) {
                    unset($state['queue'][$index]);
                } elseif (!isset($blocked[$item['chat']])) {
                    if (($this->send)($this->config, $item['chat'], $item['text'])) {
                        unset($state['queue'][$index]);
                    } else {
                        $blocked[$item['chat']] = true;
                    }
                }
                // Save each successful recipient before attempting the next.
                $saved = $state;
                $saved['queue'] = array_values($saved['queue']);
                JsonFile::write($path, $saved);
            }
            return ['healthy' => $healthy, 'detail' => $detail, 'pending' => count($state['queue'])];
        } finally {
            fclose($lock);
        }
    }
}
