# Telegram-support-reports

## Examples

```bash
# classic
php bin/console app:chat-report 123
# C-Level
php bin/console app:chat-report 123 --style=executive

# daily reports
php bin/console app:daily-report
php bin/console app:daily-report --style=executive

# daily digests
php bin/console app:daily-digest
php bin/console app:daily-digest --style=classic
```

HTTP:
```
/report?chat=123
/report?chat=123&style=executive
```
