<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Config\Config;
use Src\Service\Database;
use Psr\Log\NullLogger;

class ConfigTest extends TestCase
{
    public function testLoadsEnvFile(): void
    {
        $dir = sys_get_temp_dir() . '/cfg' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/.env', "FOO=bar\nDATABASE_URL=sqlite:///:memory:\n");

        Config::load($dir);
        $this->assertSame('bar', Config::get('FOO'));
    }

    public function testDatabaseConnectionFromEnv(): void
    {
        $dir = sys_get_temp_dir() . '/db' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/.env', "DATABASE_URL=sqlite:///:memory:\n");
        Config::load($dir);

        $ref = new ReflectionClass(Database::class);
        $prop = $ref->getProperty('connection');
        $prop->setAccessible(true);
        $prop->setValue(null);

        $conn = Database::getConnection(new NullLogger());
        $conn->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $conn->insert('t', ['name' => 'foo']);
        $this->assertSame('foo', $conn->fetchOne('SELECT name FROM t WHERE id = 1'));
    }
}
