CREATE TABLE IF NOT EXISTS messages
(
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    chat_id      BIGINT     NOT NULL,
    chat_title   VARCHAR(255) NULL,
    message_id   BIGINT     NOT NULL,
    from_user    VARCHAR(255),
    message_date INT        NOT NULL,
    text         LONGTEXT   NOT NULL,
    attachments  LONGTEXT   NULL,
    processed    TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_chat_message (chat_id, message_id),
    INDEX idx_chat_processed (chat_id, processed),
    INDEX idx_message_date (message_date)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;