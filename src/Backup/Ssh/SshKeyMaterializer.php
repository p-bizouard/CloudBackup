<?php

declare(strict_types=1);

namespace App\Backup\Ssh;

use Symfony\Component\Filesystem\Filesystem;

final class SshKeyMaterializer
{
    public function __construct(private readonly Filesystem $filesystem = new Filesystem())
    {
    }

    public function writeTempKey(string $privateKey): string
    {
        $path = $this->filesystem->tempnam('/tmp', 'key_');
        $this->filesystem->appendToFile($path, str_replace("\r", '', $privateKey."\n"));

        return $path;
    }

    /**
     * Best-effort removal of a materialized key file. Safe to call with null.
     */
    public function cleanup(?string $path): void
    {
        if (null === $path) {
            return;
        }
        @unlink($path);
    }
}
