<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use InvalidArgumentException;

final class BundleFile
{
    public function __construct(
        public readonly string $contents,
        public readonly int $mode,
    ) {
        if ($mode < 0 || $mode > 0o7777) {
            throw new InvalidArgumentException(\sprintf('Invalid file mode: %o', $mode));
        }
    }
}
