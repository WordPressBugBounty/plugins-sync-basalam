<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

class DeleteApiService extends AbstractApiService
{
    protected function executeRequest(array $request)
    {
        return wp_remote_request($request['url'], [
            'method'  => 'DELETE',
            'headers' => $request['headers'],
            'body'    => $request['data'],
        ]);
    }

    public function send(string $url, array $headers = [], array $data = []): array
    {
        return $this->run($url, $data, $headers);
    }
}