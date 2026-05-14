<?php

namespace App\ApiModel;

use App\Entity\BackupConfiguration;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Enabled backup configuration enriched with latest backup state and type-specific fragments. Fragment properties are non-null only when the configuration matches that fragment.',
    additionalProperties: false,
)]
final class InventoryEntry
{
    public const array TYPES = [
        BackupConfiguration::TYPE_OS_INSTANCE,
        BackupConfiguration::TYPE_MYSQL,
        BackupConfiguration::TYPE_SQL_SERVER,
        BackupConfiguration::TYPE_POSTGRESQL,
        BackupConfiguration::TYPE_SSHFS,
        BackupConfiguration::TYPE_SSH_RESTIC,
        BackupConfiguration::TYPE_READ_RESTIC,
        BackupConfiguration::TYPE_READ_KOPIA,
        BackupConfiguration::TYPE_SSH_CMD,
        BackupConfiguration::TYPE_SFTP,
        BackupConfiguration::TYPE_RCLONE,
        BackupConfiguration::TYPE_KUBECONFIG,
    ];

    public function __construct(
        #[OA\Property(example: 17)]
        public int $id,
        #[OA\Property(example: 'prod-postgres')]
        public string $name,
        #[OA\Property(enum: self::TYPES, example: BackupConfiguration::TYPE_POSTGRESQL)]
        public string $type,
        #[OA\Property(description: 'Minimum expected backup size in bytes.', nullable: true)]
        public ?int $expectedSize = null,
        #[OA\Property(nullable: true)]
        public ?BackupEntry $latestBackup = null,
        #[OA\Property(nullable: true)]
        public ?BackupEntry $latestSuccessfulBackup = null,
        #[OA\Property(description: 'Shell command used to dump the configuration. Null when a dump-fragment builder applies.', nullable: true)]
        public ?string $dumpCommand = null,
        #[OA\Property(nullable: true)]
        public ?HostEntry $host = null,
        #[OA\Property(nullable: true)]
        public ?OsInstanceEntry $osInstance = null,
        #[OA\Property(description: 'Kubernetes namespace. Set only for kubeconfig backups.', nullable: true)]
        public ?string $kubeNamespace = null,
        #[OA\Property(nullable: true)]
        public ?DbConnectionEntry $postgresql = null,
        #[OA\Property(nullable: true)]
        public ?DbConnectionEntry $mysql = null,
        #[OA\Property(nullable: true)]
        public ?S3Entry $s3 = null,
    ) {
    }
}
