<?php

namespace App\Service\Inventory\Builder;

use App\Entity\BackupConfiguration;
use App\Service\Inventory\DumpFragmentInventoryBuilderInterface;

final class MysqlInventoryBuilder implements DumpFragmentInventoryBuilderInterface
{
    private const string BINARY = 'mysqldump';

    private const array HOST_PATTERNS = [
        '/--host=(\S+)/',
        '/--host\s+(\S+)/',
        '/(?<![\w-])-h\s+(\S+)/',
        '/(?<![\w-])-h([^\s\-]\S*)/',
    ];

    private const array USER_PATTERNS = [
        '/--user=(\S+)/',
        '/--user\s+(\S+)/',
        '/(?<![\w-])-u\s+(\S+)/',
        '/(?<![\w-])-u([^\s\-]\S*)/',
    ];

    private const array PORT_PATTERNS = [
        '/--port=(\d+)/',
        '/--port\s+(\d+)/',
        '/(?<![\w-])-P\s+(\d+)/',
        '/(?<![\w-])-P(\d+)/',
    ];

    private const array DATABASE_PATTERNS = [
        '/--databases\s+(\S+)/',
    ];

    private const array SHORT_FLAGS_WITH_VALUE = ['-h', '-u', '-P', '-p', '-r', '-S', '-T'];

    private const array LONG_FLAGS_WITH_VALUE = [
        '--host', '--user', '--port', '--password', '--databases',
        '--tables', '--ignore-table', '--default-character-set',
        '--result-file', '--socket',
    ];

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_MYSQL === $backupConfiguration->getType();
    }

    public function build(BackupConfiguration $backupConfiguration): array
    {
        $command = $backupConfiguration->getDumpCommand();
        $result = ['host' => null, 'port' => null, 'user' => null, 'database' => null];

        if (null === $command || '' === trim($command)) {
            $result['host'] = 'localhost';

            return ['mysql' => $result];
        }

        $result['host'] = $this->matchFirst($command, self::HOST_PATTERNS);
        $result['user'] = $this->matchFirst($command, self::USER_PATTERNS);
        $port = $this->matchFirst($command, self::PORT_PATTERNS);
        $result['port'] = null !== $port ? (int) $port : null;
        $result['database'] = $this->matchFirst($command, self::DATABASE_PATTERNS)
            ?? $this->extractPositionalDatabase($command);

        if (null === $result['host']) {
            $result['host'] = 'localhost';
        }

        return ['mysql' => $result];
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
