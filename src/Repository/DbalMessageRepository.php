<?php
// src/Repository/DbalMessageRepository.php
declare(strict_types=1);

namespace Src\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Throwable;

class DbalMessageRepository implements MessageRepositoryInterface
{
    /** @var array<int,string> */
    private array $titleCache = [];

    public function __construct(private Connection $conn, private LoggerInterface $logger)
    {
    }

    public function add(int $chatId, array $message): void
    {
        $this->conn->beginTransaction();
        try {
            // Upsert chat title
            try {
                $this->conn->insert('chats', [
                    'id'    => $chatId,
                    'title' => $message['chat_title'] ?? null,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $this->conn->update('chats', ['title' => $message['chat_title'] ?? null], ['id' => $chatId]);
            }

            // Extract reply_to (support several Telegram payload shapes)
            $replyTo = null;
            if (isset($message['reply_to']['message_id'])) {
                $replyTo = (int)$message['reply_to']['message_id'];
            } elseif (isset($message['reply_to_message']['message_id'])) {
                $replyTo = (int)$message['reply_to_message']['message_id'];
            } elseif (isset($message['reply_to_message_id'])) {
                $replyTo = (int)$message['reply_to_message_id'];
            }

            // Insert message
            try {
                $this->conn->insert('messages', [
                    'chat_id' => $chatId,
                    'message_id' => (int)$message['message_id'],
                    'reply_to' => $replyTo,
                    'from_user' => (string)($message['from']['username'] ?? ''),
                    'message_date' => (int)$message['date'],
                    'text' => (string)($message['text'] ?? ''),
                    'attachments' => $message['attachments'] ?? null,
                    'processed' => 0,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Ignore duplicates (uq_chat_message)
            }

            $this->conn->commit();
            $this->logger->info('Stored message', [
                'chat_id' => $chatId,
                'message_id' => $message['message_id'],
                'reply_to' => $replyTo,
            ]);
        } catch (Throwable $e) {
            $this->conn->rollBack();
            $this->logger->error('Failed to store message', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function listActiveChats(int $dayTs): array
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $sql = 'SELECT DISTINCT chat_id
                  FROM messages
                  WHERE processed = 0 AND message_date BETWEEN :start AND :end';
        $rows = $this->conn->fetchAllAssociative($sql, ['start' => $start, 'end' => $end]);
        $chats = array_column($rows, 'chat_id');
        $this->logger->info('Active chats fetched', ['count' => count($chats)]);
        return $chats;
    }

    public function getMessagesForChat(int $chatId, int $dayTs): array
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $sql = 'SELECT message_id, reply_to, from_user, message_date, text
                  FROM messages
                  WHERE chat_id = :chat AND processed = 0 AND message_date BETWEEN :start AND :end
                  ORDER BY message_date, message_id';
        $rows = $this->conn->fetchAllAssociative($sql, ['chat' => $chatId, 'start' => $start, 'end' => $end]);
        $this->logger->info('Messages fetched', ['chat_id' => $chatId, 'count' => count($rows)]);
        return $rows;
    }

    public function getAllMessagesForChat(int $chatId, int $dayTs): array
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $sql = 'SELECT message_id, reply_to, from_user, message_date, text
                  FROM messages
                  WHERE chat_id = :chat AND message_date BETWEEN :start AND :end
                  ORDER BY message_date, message_id';
        $rows = $this->conn->fetchAllAssociative($sql, ['chat' => $chatId, 'start' => $start, 'end' => $end]);
        $this->logger->info('All messages fetched', ['chat_id' => $chatId, 'count' => count($rows)]);
        return $rows;
    }

    public function markProcessed(int $chatId, int $dayTs): void
    {
        $start = strtotime('midnight', $dayTs);
        $end = $start + 86400 - 1;
        $sql = 'UPDATE messages
                  SET processed = 1
                  WHERE chat_id = :chat AND processed = 0 AND message_date BETWEEN :start AND :end';
        $this->conn->executeStatement($sql, ['chat' => $chatId, 'start' => $start, 'end' => $end]);
        $this->logger->info('Messages marked processed', ['chat_id' => $chatId]);
    }

    public function resetAllProcessed(): void
    {
        $this->conn->executeStatement('UPDATE messages SET processed = 0');
        $this->logger->info('All messages marked unprocessed');
    }

    public function listChats(): array
    {
        $rows = $this->conn->fetchAllAssociative('SELECT id, title FROM chats ORDER BY id');
        $this->logger->info('Chats fetched', ['count' => count($rows)]);
        return $rows;
    }

    public function getChatTitle(int $chatId): string
    {
        if (!array_key_exists($chatId, $this->titleCache)) {
            $row = $this->conn->fetchAssociative('SELECT title FROM chats WHERE id = ?', [$chatId]);
            $this->titleCache[$chatId] = $row['title'] ?? '';
            $this->logger->info('Chat title fetched', ['chat_id' => $chatId]);
        }
        return $this->titleCache[$chatId];
    }
}
