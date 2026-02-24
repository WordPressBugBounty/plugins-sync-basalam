<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Hamsalam\FetchHamsalamToken;
use SyncBasalam\Services\Hamsalam\FetchHamsalamBusinessId;

use SyncBasalam\Services\Ticket\FetchAllTickets;
use SyncBasalam\Services\Ticket\FetchTicket;
use SyncBasalam\Services\Ticket\FetchTicketSubjects;
use SyncBasalam\Services\Ticket\CreateTicket;
use SyncBasalam\Services\Ticket\CreateTicketItem;
use SyncBasalam\Services\Ticket\UploadTicketMedia;

use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

class TicketServiceManager
{
    private $hamsalamToken;
    private $hamsalamBusinessId;
    private $tokenFetcher;
    private $businessIdFetcher;

    private const MAX_RETRY_ATTEMPTS = 2;

    public static function isUnauthorized($response): bool
    {
        return is_array($response) && isset($response['status_code']) && intval($response['status_code']) === 401;
    }

    public static function ticketStatuses(): array
    {
        return [
            'pending_response' => 'در انتظار پاسخ',
            'under_review' => 'در حال بررسی',
            'closed' => 'بسته شده',
            'answered' => 'پاسخ داده شده',
        ];
    }

    public function __construct()
    {
        $settings = (array) syncBasalamSettings()->getSettings();

        $this->hamsalamToken = $settings[SettingsConfig::HAMSALAM_TOKEN] ?? null;
        $this->hamsalamBusinessId = $settings[SettingsConfig::HAMSALAM_BUSINESS_ID] ?? null;
        $this->tokenFetcher = new FetchHamsalamToken();
        $this->businessIdFetcher = new FetchHamsalamBusinessId();
    }

    private function executeWithRetry(callable $callback, array $callbackArgs = []): array
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            $attempt++;

            try {
                $token = $this->getHamsalamToken();
                if (!$this->hasValue($token)) return $this->buildErrorResponse('توکن همسلام در دسترس نیست.', 401);

                $data = call_user_func_array($callback, array_merge([$token], $callbackArgs));
            } catch (RetryableException $e) {
                return $this->buildErrorResponse($e->getMessage(), $e->getCode() ?: 400);
            } catch (NonRetryableException $e) {
                return $this->buildErrorResponse($e->getMessage(), $e->getCode() ?: 400);
            } catch (\Exception $e) {
                return $this->buildErrorResponse($e->getMessage(), 500);
            }

            if (!is_array($data) || !isset($data['status_code'])) {
                return $this->buildErrorResponse('پاسخ دریافتی از سرویس نامعتبر است.', 500);
            }

            if (!self::isUnauthorized($data)) return $data;

            if ($attempt >= self::MAX_RETRY_ATTEMPTS) return $data;

            $this->refreshHamsalamToken();
        }

        return $this->buildErrorResponse('خطای ناشناخته در پردازش درخواست.', 500);
    }

    private function buildErrorResponse(string $message, int $statusCode = 500): array
    {
        return [
            'status_code' => $statusCode,
            'error' => true,
            'message' => $message,
        ];
    }

    private function getHamsalamToken()
    {
        if ($this->hasValue($this->hamsalamToken)) return $this->hamsalamToken;

        $this->hamsalamToken = $this->tokenFetcher->fetch();
        return $this->hamsalamToken;
    }

    private function refreshHamsalamToken(): void
    {
        $this->hamsalamToken = $this->tokenFetcher->fetch();
    }

    private function getHamsalamBusinessId()
    {
        if ($this->hasValue($this->hamsalamBusinessId)) return $this->hamsalamBusinessId;

        $this->hamsalamBusinessId = $this->businessIdFetcher->fetch();
        return $this->hamsalamBusinessId;
    }

    private function hasValue($value): bool
    {
        return !($value === null || $value === '');
    }

    private function isValidTicketPayload($title, $subject, $content): bool
    {
        if (!isset($title, $subject, $content)) return false;

        $title = trim((string) $title);
        $content = trim((string) $content);

        return mb_strlen($title) >= 3
            && mb_strlen($title) <= 255
            && mb_strlen($content) >= 10;
    }

    public function CheckHamsalamAccess($page = 1): array
    {
        return $this->fetchAllTickets($page);
    }

    public function fetchTicketSubjects(): array
    {
        $service = new FetchTicketSubjects();
        return $this->executeWithRetry([$service, 'execute']);
    }

    public function fetchAllTickets($page = 1): array
    {
        $service = new FetchAllTickets();
        $page = max(1, intval($page));

        return $this->executeWithRetry([$service, 'execute'], [$page]);
    }

    public function fetchTicket($ticket_id): array
    {
        $service = new FetchTicket($ticket_id);
        return $this->executeWithRetry([$service, 'execute']);
    }

    public function uploadTicketMedia($filePath): array
    {
        $service = new UploadTicketMedia();
        return $this->executeWithRetry([$service, 'execute'], [$filePath]);
    }

    public function createTicket($title, $subject, $content, $fileIds = []): array
    {
        if (!$this->isValidTicketPayload($title, $subject, $content)) {
            return $this->buildErrorResponse('اطلاعات وارد شده معتبر نیست', 400);
        }

        $service = new CreateTicket();

        $data = [
            'title' => $title,
            'subject' => $subject,
            'content' => $content,
            'file_ids' => is_array($fileIds) ? $fileIds : [],
            'business_id' => $this->getHamsalamBusinessId(),
        ];

        return $this->executeWithRetry([$service, 'execute'], [$data]);
    }

    public function createTicketItem($ticket_id, $content, $fileIds = []): array
    {
        $service = new CreateTicketItem($ticket_id);

        $data = [
            'type' => 'content',
            'content' => $content,
            'file_ids' => is_array($fileIds) ? $fileIds : [],
        ];

        return $this->executeWithRetry([$service, 'execute'], [$data]);
    }
}
