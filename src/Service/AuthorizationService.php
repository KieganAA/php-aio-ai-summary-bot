<?php
declare(strict_types=1);

namespace Src\Service;

use Src\Config\Config;

class AuthorizationService
{
    /**
     * Check whether the given Telegram username is allowed to run bot commands.
     */
    public static function isAllowed(?string $username): bool
    {
        $allowed = Config::get('TELEGRAM_ALLOWED_TAGS');
        if ($allowed === '') {
            return true;
        }

        $allowedTags = array_map(
            static fn (string $tag): string => ltrim(trim($tag), '@'),
            array_filter(explode(',', $allowed))
        );

        $username = ltrim((string) $username, '@');

        return in_array($username, $allowedTags, true);
    }
}
