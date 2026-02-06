<?php

namespace SyncBasalam\Actions\Controller;

class ActionController
{
    protected $request;
    protected $errors = [];
    protected $messages = [];

    public function __construct()
    {
        $this->request = $_POST;

        unset($this->request['_wpnonce']);
        unset($this->request['_wp_http_referer']);
        unset($this->request['action']);
    }

    protected function validate($rules)
    {
        foreach ($rules as $field => $rule) {
            if (isset($rule['required']) && $rule['required'] && (!isset($this->request[$field]) || empty($this->request[$field]))) {
                $this->errors[$field] = $rule['message'] ?? "فیلد $field الزامی است.";

                continue;
            }
        }

        return empty($this->errors);
    }
}
