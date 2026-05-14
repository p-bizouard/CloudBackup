<?php

namespace App\ApiModel;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Host bound to a backup configuration.', additionalProperties: false)]
final class HostEntry
{
    public function __construct(
        #[OA\Property(nullable: true)]
        public ?string $name,
        #[OA\Property(nullable: true)]
        public ?string $ip,
    ) {
    }
}
