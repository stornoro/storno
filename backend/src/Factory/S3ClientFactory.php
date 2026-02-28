<?php

namespace App\Factory;

use Aws\S3\S3Client;

class S3ClientFactory
{
    public static function create(
        string $region,
        string $accessKeyId,
        string $secretAccessKey,
    ): S3Client {
        return new S3Client([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
        ]);
    }
}
