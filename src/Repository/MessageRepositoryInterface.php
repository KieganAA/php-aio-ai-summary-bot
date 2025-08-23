<?php
// src/Repository/MessageRepositoryInterface.php
declare(strict_types=1);

namespace Src\Repository;

interface MessageRepositoryInterface
{
    public function add(int $chatId, array $message): void;

    public function listActiveChats(int $dayTs): array;

    /**
     * Returns rows with fields:
     * - message_id:int, reply_to:int|null, from_user:string, message_date:int, text:string
     */
    public function getMessagesForChat(int $chatId, int $dayTs): array;

    /**
     * Fetch all messages for a chat ignoring processed flag.
     * Returns the same field set as getMessagesForChat().
     */
    public function getAllMessagesForChat(int $chatId, int $dayTs): array;

    public function markProcessed(int $chatId, int $dayTs): void;

    public function resetAllProcessed(): void;

    public function listChats(): array;

    public function getChatTitle(int $chatId): string;
}
