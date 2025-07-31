<?php
declare(strict_types=1);

namespace Src\Repository;

use PDO;
use Psr\Log\LoggerInterface;

class MySQLMessageRepository implements MessageRepositoryInterface
{
    private PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function add(int $chatId, array $message): void
    {
        $sql = <<<'SQL'
INSERT INTO messages
  (chat_id, chat_title, message_id, from_user, message_date, text, attachments)
VALUES
  (:chat, :title, :mid, :user, :dt, :text, :attach)
ON DUPLICATE KEY UPDATE
  chat_id = chat_id
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':chat' => $chatId,
            ':mid'   => $message['message_id'],
            ':title' => $message['chat_title'] ?? null,
            ':user'  => $message['from']['username'] ?? '',
            ':dt'    => $message['date'],
            ':text'  => $message['text'] ?? '',
            ':attach'=> $message['attachments'] ?? null,
        ]);
        $this->logger->info('Stored message', ['chat_id' => $chatId, 'message_id' => $message['message_id']]);
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
        $chats = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'chat_id');
        $this->logger->info('Active chats fetched', ['count' => count($chats)]);
        return $chats;
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
        $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->logger->info('Messages fetched', ['chat_id' => $chatId, 'count' => count($msgs)]);
        return $msgs;
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
        $this->logger->info('Messages marked processed', ['chat_id' => $chatId]);
    }
}
