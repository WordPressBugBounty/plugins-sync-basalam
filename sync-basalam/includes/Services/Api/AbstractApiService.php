<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

abstract class AbstractApiService
{
    protected array $defaultHeaders;
    protected ApiRequestValidator $validator;
    protected ApiResponseHandler $responseHandler;

    public function __construct()
    {
        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);

        $this->defaultHeaders = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'user-agent'   => 'Wp-Basalam',
            'referer'      => get_site_url(),
        ];

        if (!empty($token)) $this->defaultHeaders['Authorization'] = 'Bearer ' . $token;


        $this->validator = new ApiRequestValidator();
        $this->responseHandler = new ApiResponseHandler();
    }

    public function run(string $url, $data, array $headers = []): array
    {
        $validationResult = $this->validator->validate($url, $data, $headers);

        if (!$validationResult['valid']) {
            Logger::error("خطا در اعتبارسنجی ریکوئست: " . $validationResult['message']);
            return [
                'body'        => null,
                'status_code' => 400,
                'error'       => $validationResult['message']
            ];
        }

        $preparedRequest = $this->prepareRequest($url, $data, $headers);

        if (isset($preparedRequest['error'])) {
            Logger::error("خطای امنیتی: " . $preparedRequest['error']);
            return ['body' => null, 'status_code' => 401, 'error' => $preparedRequest['error']];
        }

        $response = $this->executeRequest($preparedRequest);

        return $this->responseHandler->handle($response);
    }

    protected function prepareRequest(string $url,  $data, array $headers): array
    {
        $headers = array_merge($this->defaultHeaders, $headers);

        return [
            'url'     => $url,
            'data'    => json_encode($data),
            'headers' => $headers,
        ];
    }

    abstract protected function executeRequest(array $request);
}
