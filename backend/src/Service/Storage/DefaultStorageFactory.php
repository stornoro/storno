<?php

namespace App\Service\Storage;

use League\Flysystem\FilesystemOperator;

class DefaultStorageFactory
{
    public function __construct(
        private readonly FilesystemOperator $awsStorage,
        private readonly FilesystemOperator $localStorage,
        private readonly string $awsAccessKeyId,
    ) {}

    public function create(): FilesystemOperator
    {
        if ($this->awsAccessKeyId !== '') {
            return $this->awsStorage;
        }

        return $this->localStorage;
    }
}
