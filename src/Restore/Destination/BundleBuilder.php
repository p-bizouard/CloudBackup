<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use App\Entity\Host;
use App\Entity\Storage;
use RuntimeException;

/**
 * Helpers for constructing bundle file contents reused across strategies.
 */
final class BundleBuilder
{
    /**
     * Concatenate source + target rclone configs verbatim. Section names are
     * NOT renamed — admins must keep remote names unique across the two
     * Storages, and storage_sub_path / target_path keep their original
     * `<remote>:<path>` syntax.
     */
    public function mergeRcloneConfigs(?Storage $source, ?Storage $target): string
    {
        $parts = [];
        if (null !== $source) {
            $parts[] = trim($this->rcloneConfigOrThrow($source, 'source'));
        }
        if (null !== $target) {
            $parts[] = trim($this->rcloneConfigOrThrow($target, 'target'));
        }

        return implode("\n\n", $parts);
    }

    public function sshPrivateKey(Host $host): string
    {
        $key = $host->getPrivateKey();
        if (null === $key || '' === trim($key)) {
            throw new RuntimeException(\sprintf('Host "%s" has no SSH private key.', (string) $host));
        }
        if (!str_ends_with($key, "\n")) {
            $key .= "\n";
        }

        return $key;
    }

    /**
     * @return array{login: string, ip: string, port: int}
     */
    public function sshConnectionParts(Host $host): array
    {
        $ip = $host->getIp();
        $login = $host->getLogin();
        if (null === $ip || null === $login) {
            throw new RuntimeException(\sprintf('Host "%s" missing ip or login.', (string) $host));
        }

        return [
            'login' => $login,
            'ip' => $ip,
            'port' => $host->getPort() ?? 22,
        ];
    }

    /**
     * Render an env file suitable for `source env.txt` in bash.
     *
     * @param array<string, scalar|null> $env
     */
    public function buildEnvFile(array $env): string
    {
        $lines = [];
        foreach ($env as $key => $value) {
            if (null === $value) {
                continue;
            }
            $escaped = str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], (string) $value);
            $lines[] = \sprintf('export %s="%s"', $key, $escaped);
        }

        return implode("\n", $lines)."\n";
    }

    private function rcloneConfigOrThrow(Storage $storage, string $role): string
    {
        $config = $storage->getRcloneConfiguration();
        if (null === $config || '' === trim($config)) {
            throw new RuntimeException(\sprintf('%s storage "%s" has no rclone configuration.', ucfirst($role), (string) $storage->getName()));
        }

        return $config;
    }
}
