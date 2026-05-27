<?php

declare(strict_types=1);

namespace App\Tests\Restore\Destination;

use App\Restore\Destination\BundleFile;
use App\Restore\Destination\RestoreBundle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RestoreBundleTest extends TestCase
{
    public function testWriteToDirectoryPlacesFilesWithCorrectModes(): void
    {
        $bundle = new RestoreBundle('restore-mysql-1', [
            'restore.sh' => new BundleFile("#!/usr/bin/env bash\necho hi\n", 0o700),
            'rclone.conf' => new BundleFile("[source]\ntype=s3\n", 0o600),
            'MANIFEST.txt' => new BundleFile("test\n", 0o644),
        ]);
        $tmp = sys_get_temp_dir().'/restore-bundle-test-'.bin2hex(random_bytes(4));
        mkdir($tmp);

        try {
            $outDir = $bundle->writeToDirectory($tmp);

            self::assertDirectoryExists($outDir);
            self::assertSame($tmp.'/restore-mysql-1', $outDir);
            self::assertSame(0o700, fileperms($outDir.'/restore.sh') & 0o777);
            self::assertSame(0o600, fileperms($outDir.'/rclone.conf') & 0o777);
            self::assertSame(0o644, fileperms($outDir.'/MANIFEST.txt') & 0o777);
            self::assertStringContainsString('echo hi', (string) file_get_contents($outDir.'/restore.sh'));
        } finally {
            $this->rmrf($tmp);
        }
    }

    public function testWriteToDirectoryRefusesOverwriteWithoutForce(): void
    {
        $bundle = new RestoreBundle('restore-x-1', [
            'a.txt' => new BundleFile('a', 0o600),
        ]);
        $tmp = sys_get_temp_dir().'/restore-bundle-test-'.bin2hex(random_bytes(4));
        mkdir($tmp);
        mkdir($tmp.'/restore-x-1');

        try {
            $this->expectException(RuntimeException::class);
            $bundle->writeToDirectory($tmp);
        } finally {
            $this->rmrf($tmp);
        }
    }

    public function testToTarGzProducesReadableArchive(): void
    {
        $bundle = new RestoreBundle('restore-y-2', [
            'restore.sh' => new BundleFile("echo y\n", 0o700),
            'MANIFEST.txt' => new BundleFile("meta\n", 0o644),
        ]);

        $bytes = $bundle->toTarGz();
        self::assertNotSame('', $bytes);
        self::assertSame("\x1f\x8b", substr($bytes, 0, 2), 'expected gzip magic bytes');
    }

    public function testInvalidNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RestoreBundle('../escape', []);
    }

    public function testInvalidFilePathRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RestoreBundle('ok', ['/abs' => new BundleFile('x', 0o600)]);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
