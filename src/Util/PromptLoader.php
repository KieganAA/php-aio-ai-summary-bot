<?php
// Src/Util/PromptLoader.php
declare(strict_types=1);

namespace Src\Util;

final class PromptLoader
{
    private static array $cache = [];

    public static function load(string $name): array
    {
        $base = dirname(__DIR__) . '/prompts/';
        $path = $base . $name . '.yml';
        $key = realpath($path) ?: $path;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $parsed = [];
        if (is_file($path)) {
            if (function_exists('yaml_parse_file')) {
                $parsed = yaml_parse_file($path) ?: [];
            } else {
                // Minimal fallback: read file and extract 'system:' block
                $raw = (string)@file_get_contents($path);
                if (preg_match('/^system:\s*\|\s*\n(.*)$/sU', $raw, $m)) {
                    $parsed['system'] = rtrim($m[1]);
                }
            }
        }
        return self::$cache[$key] = (is_array($parsed) ? $parsed : []);
    }

    public static function system(string $name, array $vars = []): string
    {
        $prompt = self::load($name);
        $system = (string)($prompt['system'] ?? '');

        if ($vars) {
            // support {{ key }} and {{key}}
            foreach ($vars as $k => $v) {
                $system = strtr($system, [
                    '{{ ' . $k . ' }}' => (string)$v,
                    '{{' . $k . '}}' => (string)$v,
                ]);
            }
        }
        return $system;
    }
}
