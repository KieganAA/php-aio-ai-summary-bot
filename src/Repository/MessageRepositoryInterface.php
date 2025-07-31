<?php
declare(strict_types=1);

namespace Src\Repository;

interface MessageRepositoryInterface
{
    public function add(int $chatId, array $message): void;

    public function listActiveChats(int $dayTs): array;

    public function getMessagesForChat(int $chatId, int $dayTs): array;

    public function markProcessed(int $chatId, int $dayTs): void;
}
