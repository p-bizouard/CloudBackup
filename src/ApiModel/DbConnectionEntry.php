<?php

namespace App\ApiModel;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Database connection coordinates parsed from the dump command.', additionalProperties: false)]
final class DbConnectionEntry
{
    public function __construct(
        #[OA\Property(nullable: true)]
        public ?string $host,
        #[OA\Property(nullable: true)]
        public ?int $port,
        #[OA\Property(nullable: true)]
        public ?string $user,
        #[OA\Property(nullable: true)]
        public ?string $database,
    ) {
    }
}
