<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

class PutApiService extends AbstractApiService
{

    protected function executeRequest(array $request)
    {
        return wp_remote_request($request['url'], [
            'method'  => 'PUT',
            'body'    => $request['data'],
            'headers' => $request['headers'],
            'timeout' => 10,
        ]);
    }

    public function send(string $url, array $data = [], array $headers = []): array
    {
        return $this->run($url, $data, $headers);
    }
}
