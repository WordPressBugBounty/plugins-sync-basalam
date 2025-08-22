<?php
if (! defined('ABSPATH')) exit;

class Sync_basalamController
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
    protected function addError($message)
    {
        $this->errors[] = $message;
        $this->setFlashErrors();
    }

    protected function addMessage($message)
    {
        $this->messages[] = $message;
        $this->setFlashMessages();
    }

    protected function setFlashErrors()
    {
        if (!empty($this->errors)) {
            set_transient('controller_errors', $this->errors, 60);
        }
    }

    protected function setFlashMessages()
    {
        if (!empty($this->messages)) {
            set_transient('controller_messages', $this->messages, 60);
        }
    }

    protected function error($message, $redirect = null)
    {
        $this->addError($message);
        return [
            'success' => false,
            'redirect' => $redirect ?: wp_get_referer()
        ];
    }

    protected function success($message, $redirect = null)
    {
        $this->addMessage($message);
        return [
            'success' => true,
        ];
    }
}
