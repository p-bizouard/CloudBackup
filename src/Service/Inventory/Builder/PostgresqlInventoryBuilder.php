<?php

namespace App\Service\Inventory\Builder;

use App\ApiModel\DbConnectionEntry;
use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Service\Inventory\DumpFragmentInventoryBuilderInterface;

final class PostgresqlInventoryBuilder implements DumpFragmentInventoryBuilderInterface
{
    private const string BINARY = 'pg_dump';

    private const array HOST_PATTERNS = [
        '/--host=(\S+)/',
        '/--host\s+(\S+)/',
        '/(?<![\w-])-h\s+(\S+)/',
    ];

    private const array USER_PATTERNS = [
        '/--username=(\S+)/',
        '/--username\s+(\S+)/',
        '/(?<![\w-])-U\s+(\S+)/',
    ];

    private const array PORT_PATTERNS = [
        '/--port=(\d+)/',
        '/--port\s+(\d+)/',
        '/(?<![\w-])-p\s+(\d+)/',
    ];

    private const array DATABASE_PATTERNS = [
        '/--dbname=(\S+)/',
        '/--dbname\s+(\S+)/',
        '/(?<![\w-])-d\s+(\S+)/',
    ];

    private const array SHORT_FLAGS_WITH_VALUE = ['-U', '-h', '-p', '-d', '-T', '-n', '-N', '-t', '-f', '-F'];

    private const array LONG_FLAGS_WITH_VALUE = [
        '--host', '--port', '--username', '--dbname', '--password',
        '--file', '--format', '--table', '--exclude-table',
        '--schema', '--exclude-schema',
    ];

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_POSTGRESQL === $backupConfiguration->getType();
    }

    public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $inventoryEntry): void
    {
        $command = $backupConfiguration->getDumpCommand();

        if (null === $command || '' === trim($command)) {
            $inventoryEntry->postgresql = new DbConnectionEntry(host: 'localhost', port: null, user: null, database: null);

            return;
        }

        $port = $this->matchFirst($command, self::PORT_PATTERNS);
        $inventoryEntry->postgresql = new DbConnectionEntry(
            host: $this->matchFirst($command, self::HOST_PATTERNS) ?? 'localhost',
            port: null !== $port ? (int) $port : null,
            user: $this->matchFirst($command, self::USER_PATTERNS),
            database: $this->matchFirst($command, self::DATABASE_PATTERNS) ?? $this->extractPositionalDatabase($command),
        );
    }

    /**
     * @param string[] $patterns
     */
    private function matchFirst(string $command, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $command, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function extractPositionalDatabase(string $command): ?string
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        $startCollect = false;
        $skipNext = false;
        foreach ($tokens as $token) {
            if (!$startCollect) {
                if (self::BINARY === $token) {
                    $startCollect = true;
                }
                continue;
            }
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if (\in_array($token, self::SHORT_FLAGS_WITH_VALUE, true)
                || \in_array($token, self::LONG_FLAGS_WITH_VALUE, true)) {
                $skipNext = true;
                continue;
            }
            if (str_starts_with($token, '-') || str_contains($token, '=')) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
