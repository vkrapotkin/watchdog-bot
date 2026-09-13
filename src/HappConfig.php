<?php

namespace Vkrapotkin\WatchdogBot;

use InvalidArgumentException;

final class HappConfig
{
    public static function convert(array $source, int $index = 0): array
    {
        $nodes = array_values(array_filter($source['outbounds'] ?? [], static fn ($node) =>
            ($node['protocol'] ?? '') === 'vless' &&
            in_array($node['streamSettings']['network'] ?? '', ['tcp', 'raw'], true) &&
            ($node['streamSettings']['security'] ?? '') === 'reality'
        ));
        if (!isset($nodes[$index])) {
            throw new InvalidArgumentException('No matching VLESS TCP REALITY node at requested index');
        }
        $node = $nodes[$index];
        // Import only transport/account settings, not Happ routing or local listeners.
        $outbound = ['tag' => 'vpn', 'protocol' => 'vless', 'settings' => $node['settings'], 'streamSettings' => $node['streamSettings']];
        unset($outbound['streamSettings']['sockopt']);
        return [
            'log' => ['loglevel' => 'none'],
            'inbounds' => [[
                'tag' => 'telegram-local', 'listen' => '127.0.0.1', 'port' => 10880,
                'protocol' => 'socks', 'settings' => ['auth' => 'noauth', 'udp' => false],
            ]],
            'outbounds' => [ ['tag' => 'blocked', 'protocol' => 'blackhole'], $outbound ],
            'routing' => [ 'domainStrategy' => 'AsIs', 'rules' => [[
                'type' => 'field', 'inboundTag' => ['telegram-local'],
                'domain' => ['full:api.telegram.org'], 'port' => '443',
                'network' => 'tcp', 'outboundTag' => 'vpn',
            ]] ],
        ];
    }
}
