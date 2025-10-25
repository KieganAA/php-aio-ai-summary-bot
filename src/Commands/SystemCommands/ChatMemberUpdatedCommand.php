<?php
declare(strict_types=1);

namespace Src\Commands\SystemCommands;

use Exception;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Src\Service\LoggerService;

class ChatMemberUpdatedCommand extends SystemCommand
{
    protected $name = 'chatmember';
    protected $description = 'Handles channel/group membership updates';

    protected $version = '1.0.0';
    private LoggerInterface $logger;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->logger = LoggerService::getLogger();
    }

    public function execute(): ServerResponse
    {
        $raw = $this->getUpdate()->getRawData();
        $cm = $raw['chat_member'] ?? null;
        if (!$cm) return Request::emptyResponse();

        $chat = $cm['chat'] ?? [];
        if (($chat['type'] ?? '') !== 'channel') return Request::emptyResponse();

        $new_status = $cm['new_chat_member']['status'] ?? '';
        if (!in_array($new_status, ['member', 'administrator'], true)) {
            return Request::emptyResponse();
        }

        $user = $cm['new_chat_member']['user'] ?? [];
        $tg_id = (int)($user['id'] ?? 0);
        $username = $user['username'] ?? '';

        $inv = $cm['invite_link'] ?? null;
        $visitUuid = 'de75819e-60a9-46a1-9608-090724c71c17';

        if ($visitUuid) {
            try {
                $convJoin = '2a0dd9df-ab0a-4aca-8012-4e2a891906c6';
                $this->fireJoinPostback($visitUuid, $convJoin, $tg_id, $username);
                $this->logger->info('Channel join postback OK', [
                    'visit_uuid' => $visitUuid,
                    'tg_id' => $tg_id,
                ]);
            } catch (Exception $e) {
                $this->logger->error('Channel join postback failed', [
                    'e' => $e->getMessage(),
                    'visit_uuid' => $visitUuid,
                    'tg_id' => $tg_id,
                ]);
            }
        } else {
            $this->logger->warning('Join without attributed UUID', [
                'tg_id' => $tg_id,
            ]);
        }

        return Request::emptyResponse();
    }

    private function fireJoinPostback(string $visitUuid, string $conversionTypeUuid, int $tgId, ?string $username): void
    {
        $endpoint = 'https://app.aio.tech/api/v1/trigger/conversion-request';
        $qs = http_build_query([
            'visit_uuid' => $visitUuid,
            'conversion_type_uuid' => $conversionTypeUuid,
            'visit[tg_id]' => $tgId,
            'visit[tg_username]' => $username ?? '',
        ]);
        $url = $endpoint . '?' . $qs;

        $ch = curl_init($url);
        if ($ch === false) throw new Exception('curl_init failed');

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
    }
}
