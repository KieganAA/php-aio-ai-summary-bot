<?php
declare(strict_types=1);

namespace Src\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240701000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial normalized schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chats (id BIGINT AUTO_INCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB');
        $this->addSql('CREATE TABLE messages (id BIGINT AUTO_INCREMENT NOT NULL, chat_id BIGINT NOT NULL, message_id BIGINT NOT NULL, from_user VARCHAR(255) NOT NULL, message_date INT NOT NULL, text LONGTEXT NOT NULL, attachments LONGTEXT DEFAULT NULL, processed TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB');
        $this->addSql('ALTER TABLE messages ADD CONSTRAINT FK_messages_chat FOREIGN KEY (chat_id) REFERENCES chats (id)');
        $this->addSql('CREATE UNIQUE INDEX uq_chat_message ON messages (chat_id, message_id)');
        $this->addSql('CREATE INDEX idx_messages_chat_processed ON messages (chat_id, processed)');
        $this->addSql('CREATE INDEX idx_message_date ON messages (message_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE chats');
    }
}
