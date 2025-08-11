<?php
declare(strict_types=1);

namespace Src\Util;

class PromptLoader
{
    public static function load(string $name): array
    {
        $base = dirname(__DIR__) . '/prompts/';
        $path = $base . $name . '.yml';
        if (is_file($path) && function_exists('yaml_parse_file')) {
            $parsed = yaml_parse_file($path);
            if (is_array($parsed)) {
                return $parsed;
            }
        }
        return [];
    }

    public static function system(string $name, array $vars = []): string
    {
        $prompt = self::load($name);
        $system = $prompt['system'] ?? '';
        if ($vars) {
            $replace = [];
            foreach ($vars as $key => $value) {
                $replace['{{ ' . $key . ' }}'] = (string) $value;
            }
            $system = strtr($system, $replace);
        }
        return $system;
    }
}
