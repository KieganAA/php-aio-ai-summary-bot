<?php
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Src\Repository\DbalMessageRepository;
use Src\Service\Database;
use Psr\Log\NullLogger;

class DatabaseTest extends TestCase
{
    private \Doctrine\DBAL\Connection $conn;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        Database::setConnection($this->conn);
        $this->conn->executeStatement('CREATE TABLE chats (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255))');
        $this->conn->executeStatement('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL, from_user VARCHAR(255) NOT NULL, message_date INT NOT NULL, text LONGTEXT NOT NULL, attachments LONGTEXT DEFAULT NULL, processed TINYINT NOT NULL DEFAULT 0)');
        $this->conn->executeStatement('CREATE UNIQUE INDEX uq_chat_message ON messages (chat_id, message_id)');
        $this->conn->executeStatement('CREATE INDEX idx_messages_chat_processed ON messages (chat_id, processed)');
        $this->conn->executeStatement('CREATE INDEX idx_message_date ON messages (message_date)');
    }

    public function testInsertAndFetch(): void
    {
        $repo = new DbalMessageRepository($this->conn, new NullLogger());
        $repo->add(1, ['message_id' => 10, 'from' => ['username' => 'u'], 'date' => time(), 'text' => 'hello']);
        $msgs = $repo->getMessagesForChat(1, time());
        $this->assertCount(1, $msgs);
        $repo->markProcessed(1, time());
        $msgs2 = $repo->getMessagesForChat(1, time());
        $this->assertCount(0, $msgs2);
    }
}
