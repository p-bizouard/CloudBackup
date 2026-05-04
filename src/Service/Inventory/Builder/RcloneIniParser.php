<?php

namespace App\Service\Inventory\Builder;

final class RcloneIniParser
{
    /**
     * @return array<string, array<string, string>>
     */
    public function parse(string $config): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $config) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $m)) {
                $current = trim($m[1]);
                $sections[$current] = [];
                continue;
            }
            if (null === $current) {
                continue;
            }
            if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
                $sections[$current][trim($m[1])] = trim($m[2]);
            }
        }

        return $sections;
    }
}
