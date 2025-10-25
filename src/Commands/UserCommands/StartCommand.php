<?php
declare(strict_types=1);

namespace Src\Commands\UserCommands;

use Exception;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Src\Service\LoggerService;

class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Start command';
    protected $usage = '/start';
    protected $version = '1.1.1';
    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    /**
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $msg = $this->getMessage();
        $from = $msg->getFrom();
        $username = $from?->getUsername();
        $chatId = $msg->getChat()->getId();
        $tgId = (int)$from?->getId();

        $visitUuid = trim($msg->getText(true) ?? '');

        if ($visitUuid) {
            try {
                $conversionTypeUuid = 'f10b0a44-2c2f-4606-8ccc-a45e1cfc9103';
                $this->firePostback($visitUuid, $conversionTypeUuid, $tgId, $username);
            } catch (Exception $e) {
                $this->logger->error('Postback failed', [
                    'error' => $e->getMessage(),
                    'visit_uuid' => $visitUuid,
                    'tg_id' => $tgId,
                    'user' => $username,
                ]);
            }
        }

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => sprintf('Hey, postback fired, %s', $chatId),
            'parse_mode' => 'Markdown',
        ]);
    }

    private function firePostback(string $visitUuid, string $conversionTypeUuid, int $tgId, ?string $username): void
    {
        $endpoint = 'https://app.aio.tech/api/v1/trigger/conversion-request';

        $qs = http_build_query([
            'visit_uuid' => $visitUuid,
            'conversion_type_uuid' => $conversionTypeUuid,
            'visit[tg_id]' => $tgId,
            'visit[tg_username]' => $username ?? 'NaN',
        ]);

        $url = $endpoint . '?' . $qs;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $status < 200 || $status >= 300) {
            throw new Exception("Postback HTTP $status errno $errno body: " . substr((string)$body, 0, 500));
        }

        $this->logger->info('Postback ok', [
            'visit_uuid' => $visitUuid,
            'conversion_type_uuid' => $conversionTypeUuid,
            'tg_id' => $tgId,
            'username' => $username,
            'status' => $status,
        ]);
    }
}
