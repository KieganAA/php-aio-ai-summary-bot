<?php
declare(strict_types=1);

namespace Src\Repository;

use PDO;

class MySQLMessageRepository implements MessageRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'),
            getenv('DB_NAME')
        );
        $this->pdo = new PDO(
            $dsn,
            getenv('DB_USER'),
            getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function add(int $chatId, array $message): void
    {
        $sql = <<<'SQL'
INSERT INTO messages
  (chat_id, message_id, from_user, message_date, text)
VALUES
  (:chat, :mid, :user, :dt, :text)
ON DUPLICATE KEY UPDATE
  chat_id = chat_id
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':chat' => $chatId,
            ':mid' => $message['message_id'],
            ':user' => $message['from']['username'] ?? '',
            ':dt' => $message['date'],
            ':text' => $message['text'] ?? '',
        ]);
    }

    public function listActiveChats(int $dayTs): array
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $stmt = $this->pdo->prepare(<<<SQL
SELECT DISTINCT chat_id
  FROM messages
 WHERE processed = 0
   AND message_date BETWEEN :start AND :end
SQL
        );
        $stmt->execute([':start' => $start, ':end' => $end]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'chat_id');
    }

    public function getMessagesForChat(int $chatId, int $dayTs): array
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $stmt = $this->pdo->prepare(<<<SQL
SELECT from_user, message_date, text
  FROM messages
 WHERE chat_id = :chat
   AND processed = 0
   AND message_date BETWEEN :start AND :end
 ORDER BY message_date
SQL
        );
        $stmt->execute([':chat' => $chatId, ':start' => $start, ':end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markProcessed(int $chatId, int $dayTs): void
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $stmt = $this->pdo->prepare(<<<SQL
UPDATE messages
   SET processed = 1
 WHERE chat_id = :chat
   AND processed = 0
   AND message_date BETWEEN :start AND :end
SQL
        );
        $stmt->execute([':chat' => $chatId, ':start' => $start, ':end' => $end]);
    }
}
