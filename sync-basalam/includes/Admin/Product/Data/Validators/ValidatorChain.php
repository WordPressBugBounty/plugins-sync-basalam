<?php

namespace SyncBasalam\Admin\Product\Data\Validators;

defined('ABSPATH') || exit;

class ValidatorChain
{
    private array $validators = [];

    public function add(ValidatorInterface $validator): self
    {
        $this->validators[] = $validator;
        return $this;
    }

    public function validate($product): void
    {
        foreach ($this->validators as $validator) {
            $validator->validate($product);
        }
    }
}