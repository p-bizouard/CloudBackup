<?php

namespace App\Utils;

class StringUtils
{
    public static function humanizeFileSize(int|string|null $size, int $precision = 0): string
    {
        if (null === $size) {
            return 'N/A';
        }

        for ($i = 0; ($size / 1024) > 0.9; ++$i) {
            $size /= 1024;
        }

        return round($size, $precision).['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'][$i];
    }
}
