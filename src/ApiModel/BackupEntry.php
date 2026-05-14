<?php

namespace App\ApiModel;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Snapshot of a single backup run.', additionalProperties: false)]
final class BackupEntry
{
    #[OA\Property(example: 4271)]
    public int $id;

    #[OA\Property(description: 'Workflow place name.', example: 'backuped')]
    public string $status;

    #[OA\Property(format: 'date-time', nullable: true)]
    public ?string $date;

    #[OA\Property(description: 'Backup size in bytes.', nullable: true, example: 1073741824)]
    public ?int $size;
}
