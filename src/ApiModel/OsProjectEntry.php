<?php

namespace App\ApiModel;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'OpenStack project owning the instance.', additionalProperties: false)]
final class OsProjectEntry
{
    public function __construct(
        #[OA\Property(nullable: true)]
        public ?string $name,
        #[OA\Property(nullable: true)]
        public ?string $tenantId,
    ) {
    }
}
