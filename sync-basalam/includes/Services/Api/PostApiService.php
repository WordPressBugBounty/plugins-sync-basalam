<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

class PostApiService extends AbstractApiService
{
    protected function executeRequest(array $request)
    {
        return wp_remote_post($request['url'], [
            'body'    => $request['data'],
            'headers' => $request['headers'],
            'timeout' => 10,
        ]);
    }

    public function send(string $url, $data, array $headers = []): array
    {
        return $this->run($url, $data, $headers);
    }
}
