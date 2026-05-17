<?php

declare(strict_types=1);

namespace App\Backup\Ssh;

use App\Entity\Host;
use InvalidArgumentException;

final class SshOptionsBuilder
{
    public function build(?Host $host): string
    {
        if (null === $host || null === $host->getSshOptions() || '' === trim((string) $host->getSshOptions())) {
            return '';
        }

        $sshOptions = trim((string) $host->getSshOptions());

        if (!preg_match(Host::SSH_OPTIONS_PATTERN, $sshOptions)) {
            throw new InvalidArgumentException('SSH options contain invalid characters. Only alphanumeric characters, spaces, and the following special characters are allowed: - = , + . / : _');
        }

        return ' '.$sshOptions;
    }
}
