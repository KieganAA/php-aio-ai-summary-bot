# Load .env file if it exists
ifneq (,$(wildcard .env))
include .env
export $(shell sed 's/=.*//' .env)
endif

chat-report:
	@echo "Running ChatReport Command"
	php bin/console app:chat-report

daily-report:
	@echo "Running DailyReport Command"
	php bin/console app:daily-report

list-chats:
	@echo "Running ListChats Command"
	php bin/console app:list-chats

reset-processed:
	@echo "Running ResetProcessed Command"
	php bin/console app:reset-processed

# Start local PHP development server
server:
	@echo "Starting local PHP server..."
	php -S localhost:8080 -t public

# Start ngrok
tunnel:
	@echo "Starting ngrok..."
	ngrok http 8080

# Set Telegram webhook
webhook-local:
	@echo "Setting Telegram webhook..."
	curl -F "url=$(WEBHOOK_URL)/index.php" \
	"https://api.telegram.org/bot$(TELEGRAM_BOT_TOKEN)/setWebhook"

webhook:
	@echo "Setting Telegram webhook..."
	curl -F "url=$(NGROK_URL)/index.php" \
	"https://api.telegram.org/bot$(TELEGRAM_BOT_TOKEN)/setWebhook"

# Clear Telegram webhook
webhook-clear:
	@echo "Clearing Telegram webhook..."
	curl -F "url=" "https://api.telegram.org/bot$(TELEGRAM_BOT_TOKEN)/deleteWebhook"

# Get webhook info
webhook-info:
	@echo "Fetching Telegram webhook information..."
	curl "https://api.telegram.org/bot$(TELEGRAM_BOT_TOKEN)/getWebhookInfo" | jq

.PHONY: restart
restart:
	@echo "Restarting services..."
	sudo systemctl restart nginx
	sudo systemctl restart mysql
	sudo systemctl restart php8.3-fpm

syslog:
	tail -f /var/log/syslog

authlog:
	tail -f /var/log/auth.log

nginx-access:
	tail -f /var/log/nginx/access.log

nginx-error:
	tail -f /var/log/nginx/error.log

mysql-error:
	tail -f /var/log/mysql/error.log

app-log:
	tail -f /var/www/summary-bot/logs/app.log

summary-dir:
	cd /var/www/summary-bot && bash
