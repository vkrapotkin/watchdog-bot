<?php

namespace Vkrapotkin\WatchdogBot;

use RuntimeException;

final class JsonFile
{
    public static function read(string $path): array
    {
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Expected a JSON object');
        }
        return $data;
    }

    public static function write(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create private directory');
        }
        $temporary = tempnam($directory, '.watchdog-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary file');
        }
        try {
            chmod($temporary, 0600);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot save JSON file');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
