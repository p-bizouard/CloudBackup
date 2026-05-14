<?php

namespace App\ApiModel;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'S3 bucket coordinates extracted from the rclone configuration.', additionalProperties: false)]
final class S3Entry
{
    public function __construct(
        #[OA\Property(nullable: true)]
        public ?string $bucket,
        #[OA\Property(nullable: true)]
        public ?string $accessKeyId,
        #[OA\Property(nullable: true)]
        public ?string $region,
        #[OA\Property(nullable: true)]
        public ?string $endpoint,
    ) {
    }
}
