<?php
declare(strict_types=1);

use Longman\TelegramBot\Entities\Update;
use PHPUnit\Framework\TestCase;
use Src\Logger\MessageLogger;
use Src\Repository\DbalMessageRepository;
use Src\Service\Database;
use Psr\Log\NullLogger;
use Doctrine\DBAL\DriverManager;

class MessageLoggerTest extends TestCase
{
    private \Doctrine\DBAL\Connection $conn;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        Database::setConnection($this->conn);
        $this->conn->executeStatement('CREATE TABLE chats (id INTEGER PRIMARY KEY, title VARCHAR(255))');
        $this->conn->executeStatement('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL, from_user VARCHAR(255) NOT NULL, message_date INT NOT NULL, text LONGTEXT NOT NULL, attachments LONGTEXT DEFAULT NULL, processed TINYINT NOT NULL DEFAULT 0)');
        $this->conn->executeStatement('CREATE UNIQUE INDEX uq_chat_message ON messages (chat_id, message_id)');
    }

    public function testStoresMessageForAioChat(): void
    {
        $data = [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'date' => 1,
                'chat' => ['id' => 1, 'title' => 'My AIO Group', 'type' => 'group'],
                'from' => ['id' => 2, 'username' => 'u'],
                'text' => 'hi',
            ],
        ];
        $update = new Update($data);
        $repo = new DbalMessageRepository($this->conn, new NullLogger());
        $logger = new MessageLogger($repo, new NullLogger());
        $logger->handleUpdate($update);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messages');
        $this->assertSame(1, $count);
    }

    public function testIgnoresOtherChats(): void
    {
        $data = [
            'update_id' => 2,
            'message' => [
                'message_id' => 11,
                'date' => 1,
                'chat' => ['id' => 2, 'title' => 'Random Group', 'type' => 'group'],
                'from' => ['id' => 3, 'username' => 'v'],
                'text' => 'hello',
            ],
        ];
        $update = new Update($data);
        $repo = new DbalMessageRepository($this->conn, new NullLogger());
        $logger = new MessageLogger($repo, new NullLogger());
        $logger->handleUpdate($update);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messages');
        $this->assertSame(0, $count);
    }
}
