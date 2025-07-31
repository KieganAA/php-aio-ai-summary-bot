# Telegram Multi Chat Summarizer

This project is a Telegram bot that summarizes chats using the Deepseek API.

## Running database migrations

Ensure dependencies are installed and your `.env` file is configured. To run Doctrine migrations with the project configuration loaded, use:

```bash
composer migrate -- [doctrine options]
```

This command executes `bin/migrate` which loads environment variables via `Config::load()` before delegating to `vendor/bin/doctrine-migrations`.

You may also run the script directly:

```bash
php bin/migrate -- [doctrine options]
```

## Features

- `/summarize` now shows a keyboard with all known chats and accepts an optional date so you can request summaries for any conversation.
- Daily reports are delivered to Telegram and also forwarded to Slack and Notion if configured.
