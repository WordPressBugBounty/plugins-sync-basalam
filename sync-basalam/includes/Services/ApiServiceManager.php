<?php

namespace SyncBasalam\Services;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\Api\PostApiService;
use SyncBasalam\Services\Api\GetApiService;
use SyncBasalam\Services\Api\PatchApiService;
use SyncBasalam\Services\Api\PutApiService;
use SyncBasalam\Services\Api\DeleteApiService;
use SyncBasalam\Services\Api\FileUploadApiService;

defined('ABSPATH') || exit;

class ApiServiceManager
{
    private PostApiService $postService;
    private GetApiService $getService;
    private PatchApiService $patchService;
    private PutApiService $putService;
    private DeleteApiService $deleteService;
    private FileUploadApiService $fileUploadService;

    public function __construct()
    {
        $this->postService = new PostApiService();
        $this->getService = new GetApiService();
        $this->patchService = new PatchApiService();
        $this->putService = new PutApiService();
        $this->deleteService = new DeleteApiService();
        $this->fileUploadService = new FileUploadApiService();
    }

    public function sendPostRequest($url, $data, $headers = [])
    {
        $response = $this->postService->send($url, $data, $headers);
        return $response;
    }

    public function sendGetRequest($url, $headers = [])
    {
        $response = $this->getService->send($url, $headers);
        return $response;
    }

    public function sendPatchRequest($url, $data, $headers = [])
    {
        $response = $this->patchService->send($url, $data, $headers);
        return $response;
    }

    public function sendPutRequest($url, $data, $headers = [])
    {
        $response = $this->putService->send($url, $data, $headers);
        return $response;
    }

    public function sendDeleteRequest($url, $headers = [], $data = null)
    {
        $response = $this->deleteService->send($url, $headers, (array) $data);
        return $response;
    }

    public function uploadFileRequest($url, $localFile, $data = [], $headers = [])
    {
        $response = $this->fileUploadService->upload($url, $localFile, $data, $headers);
        return $response;
    }
}
