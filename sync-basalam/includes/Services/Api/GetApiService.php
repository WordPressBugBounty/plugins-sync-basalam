<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

class GetApiService extends AbstractApiService
{
    protected function executeRequest(array $request)
    {
        return wp_remote_get($request['url'], [
            'timeout' => 30,
            'headers' => $request['headers'],
        ]);
    }

    public function send(string $url, array $headers = []): array
    {
        return $this->run($url, [], $headers);
    }
}
