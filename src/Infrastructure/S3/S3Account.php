<?php

namespace CipiGui\Infrastructure\S3;

use CipiGui\Infrastructure\ConnectorException;

/**
 * The API client of an S3 provider's account: its S3 endpoint and, where the
 * provider has one, its IAM (Wasabi). AwsClient answers the same two calls.
 */
final class S3Account
{
    public function __construct(
        private readonly S3Client $s3,
        private readonly ?Iam $iam = null,
    ) {}

    public function s3(): S3Client
    {
        return $this->s3;
    }

    public function iam(): Iam
    {
        return $this->iam ?? throw new ConnectorException(__('This provider makes no keys through an API.'));
    }
}
