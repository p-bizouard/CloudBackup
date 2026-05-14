<?php

namespace App\ApiModel;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'OpenStack instance metadata.', additionalProperties: false)]
final class OsInstanceEntry
{
    public function __construct(
        #[OA\Property(nullable: true)]
        public ?string $name,
        #[OA\Property(nullable: true)]
        public ?string $id,
        #[OA\Property(nullable: true)]
        public ?string $osRegionName,
        #[OA\Property(nullable: true)]
        public ?OsProjectEntry $osProject,
    ) {
    }
}
