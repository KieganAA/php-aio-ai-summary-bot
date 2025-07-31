<?php
declare(strict_types=1);

namespace Src\Repository;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class DbalMessageRepository implements MessageRepositoryInterface
{
    public function __construct(private Connection $conn, private LoggerInterface $logger)
    {
    }

    public function add(int $chatId, array $message): void
    {
        $this->conn->beginTransaction();
        try {
            $this->conn->executeStatement(
                'INSERT INTO chats (id, title) VALUES (:id, :title)
                 ON CONFLICT(id) DO UPDATE SET title=excluded.title',
                ['id' => $chatId, 'title' => $message['chat_title'] ?? null]
            );

            try {
                $this->conn->insert('messages', [
                    'chat_id'    => $chatId,
                    'message_id' => $message['message_id'],
                    'from_user'  => $message['from']['username'] ?? '',
                    'message_date' => $message['date'],
                    'text'       => $message['text'] ?? '',
                    'attachments'=> $message['attachments'] ?? null,
                    'processed'  => 0,
                ]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // ignore duplicates
            }
            $this->conn->commit();
            $this->logger->info('Stored message', ['chat_id' => $chatId, 'message_id' => $message['message_id']]);
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $this->logger->error('Failed to store message', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function listActiveChats(int $dayTs): array
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $sql = 'SELECT DISTINCT chat_id FROM messages WHERE processed = 0 AND message_date BETWEEN :start AND :end';
        $rows = $this->conn->fetchAllAssociative($sql, ['start' => $start, 'end' => $end]);
        $chats = array_column($rows, 'chat_id');
        $this->logger->info('Active chats fetched', ['count' => count($chats)]);
        return $chats;
    }

    public function getMessagesForChat(int $chatId, int $dayTs): array
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $sql = 'SELECT from_user, message_date, text FROM messages WHERE chat_id = :chat AND processed = 0 AND message_date BETWEEN :start AND :end ORDER BY message_date';
        $rows = $this->conn->fetchAllAssociative($sql, ['chat' => $chatId, 'start' => $start, 'end' => $end]);
        $this->logger->info('Messages fetched', ['chat_id' => $chatId, 'count' => count($rows)]);
        return $rows;
    }

    public function markProcessed(int $chatId, int $dayTs): void
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $sql = 'UPDATE messages SET processed = 1 WHERE chat_id = :chat AND processed = 0 AND message_date BETWEEN :start AND :end';
        $this->conn->executeStatement($sql, ['chat' => $chatId, 'start' => $start, 'end' => $end]);
        $this->logger->info('Messages marked processed', ['chat_id' => $chatId]);
    }
}
