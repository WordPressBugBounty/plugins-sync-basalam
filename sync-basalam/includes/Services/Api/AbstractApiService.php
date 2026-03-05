<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

abstract class AbstractApiService
{
    protected array $defaultHeaders;
    protected $validator;
    protected $responseHandler;
    protected $circuitBreaker;

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


        $this->validator       = new ApiRequestValidator();
        $this->responseHandler = new ApiResponseHandler();
        $this->circuitBreaker  = new CircuitBreaker();
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

        // Check circuit breaker — throws CircuitBreakerOpenException when OPEN.
        $this->circuitBreaker->isAllowed();

        $preparedRequest = $this->prepareRequest($url, $data, $headers);

        if (isset($preparedRequest['error'])) {
            Logger::error("خطای امنیتی: " . $preparedRequest['error']);
            return ['body' => null, 'status_code' => 401, 'error' => $preparedRequest['error']];
        }

        try {
            $response = $this->executeRequest($preparedRequest);
            $result   = $this->responseHandler->handle($response);
            $this->circuitBreaker->recordSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->circuitBreaker->recordFailure();
            throw $e;
        }
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
