<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Config\Config;
use Src\Service\AuthorizationService;

class AuthorizationServiceTest extends TestCase
{
    public function testAllowsAllWhenEnvMissing(): void
    {
        $dir = sys_get_temp_dir() . '/auth' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/.env', "\n");
        Config::load($dir);
        $this->assertTrue(AuthorizationService::isAllowed('anyuser'));
    }

    public function testHonorsAllowedTags(): void
    {
        $dir = sys_get_temp_dir() . '/auth' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/.env', "TELEGRAM_ALLOWED_TAGS=user1,user2\n");
        Config::load($dir);
        $this->assertTrue(AuthorizationService::isAllowed('user1'));
        $this->assertFalse(AuthorizationService::isAllowed('user3'));
    }
}
