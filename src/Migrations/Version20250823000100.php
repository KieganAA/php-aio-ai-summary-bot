<?php
// src/Migrations/Version20250823000100.php
declare(strict_types=1);

namespace Src\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add reply_to column and helpful indexes for structure-aware chunking.
 */
final class Version20250823000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add messages.reply_to and composite indexes for structure-aware processing';
    }

    public function up(Schema $schema): void
    {
        // reply_to
        $this->addSql('ALTER TABLE messages ADD reply_to BIGINT DEFAULT NULL');

        // Composite index improves day scans and stable ordering
        $this->addSql('CREATE INDEX idx_chat_date_msg ON messages (chat_id, message_date, message_id)');

        // Thread lookups / merges
        $this->addSql('CREATE INDEX idx_chat_reply_to ON messages (chat_id, reply_to)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_chat_reply_to ON messages');
        $this->addSql('DROP INDEX idx_chat_date_msg ON messages');
        $this->addSql('ALTER TABLE messages DROP reply_to');
    }
}
