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

class TicketServiceManager
{
    private $hamsalamToken;
    private $hamsalamBusinessId;
    private const MAX_RETRY_ATTEMPTS = 2;

    public static function isUnauthorized($response)
    {
        return isset($response['status_code']) && $response['status_code'] == 401;
    }

    public static function ticketStatuses()
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
        $settings = syncBasalamSettings()->getSettings();

        if ($settings[SettingsConfig::HAMSALAM_TOKEN]) {
            $this->hamsalamToken = $settings[SettingsConfig::HAMSALAM_TOKEN];
        } else {
            $fetchHamsalamTokenService = new FetchHamsalamToken();
            $this->hamsalamToken = $fetchHamsalamTokenService->fetch();
        }

        if ($settings[SettingsConfig::HAMSALAM_BUSINESS_ID]) {
            $this->hamsalamBusinessId = $settings[SettingsConfig::HAMSALAM_BUSINESS_ID];
        } else {
            $fetchHamsalamBusinessIdService = new FetchHamsalamBusinessId();
            $this->hamsalamBusinessId = $fetchHamsalamBusinessIdService->fetch();
        }
    }

    private function executeWithRetry(callable $callback, array $callbackArgs = [])
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            $attempt++;

            $data = call_user_func_array($callback, array_merge([$this->hamsalamToken], $callbackArgs));

            if ($data['status_code'] != 401) return $data;

            if ($attempt >= self::MAX_RETRY_ATTEMPTS) return $data;

            $fetchHamsalamTokenService = new FetchHamsalamToken();
            $this->hamsalamToken = $fetchHamsalamTokenService->fetch();
        }

        return $data;
    }

    public function CheckHamsalamAccess($page = 1)
    {
        $service = new FetchAllTickets();
        return $this->executeWithRetry([$service, 'execute'], [$page]);
    }

    public function fetchTicketSubjects()
    {
        $service = new FetchTicketSubjects();
        return $this->executeWithRetry([$service, 'execute']);
    }

    public function fetchAllTickets($page = 1)
    {
        $service = new FetchAllTickets();
        return $this->executeWithRetry([$service, 'execute'], [$page]);
    }

    public function fetchTicket($ticket_id)
    {
        $service = new FetchTicket($ticket_id);
        return $this->executeWithRetry([$service, 'execute']);
    }

    public function createTicket($title, $subject, $content)
    {
        if (
            !isset($title, $subject, $content) ||
            mb_strlen(trim($title)) < 3 ||
            mb_strlen(trim($title)) > 255 ||
            mb_strlen(trim($content)) < 10
        ) {
            wp_die('اطلاعات وارد شده معتبر نیست');
        }

        $service = new CreateTicket();

        $data = [
            'title' => $title,
            'subject' => $subject,
            'content' => $content,
            'business_id' => $this->hamsalamBusinessId
        ];

        return $this->executeWithRetry([$service, 'execute'], [$data]);
    }

    public function CreateTicketItem($ticket_id, $content)
    {
        $service = new CreateTicketItem($ticket_id);

        $data = [
            'type' => 'content',
            'content' => $content
        ];

        return $this->executeWithRetry([$service, 'execute'], [$data]);
    }
}
