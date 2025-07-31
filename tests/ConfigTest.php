<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Config\Config;

class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/configtest_' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env', "SOME_VAR=test_value\n");
    }

    protected function tearDown(): void
    {
        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    public function testLoadEnv(): void
    {
        Config::load($this->dir);
        $this->assertSame('test_value', Config::get('SOME_VAR'));
    }
}

