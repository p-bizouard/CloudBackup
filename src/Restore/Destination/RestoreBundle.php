<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use FilesystemIterator;
use InvalidArgumentException;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class RestoreBundle
{
    /**
     * @param array<string, BundleFile> $files keyed by relative path inside the bundle
     */
    public function __construct(
        public readonly string $name,
        public readonly array $files,
    ) {
        if ('' === $name || str_contains($name, '/') || str_contains($name, '..')) {
            throw new InvalidArgumentException(\sprintf('Invalid bundle name: %s', $name));
        }
        foreach (array_keys($files) as $path) {
            if ('' === $path || str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new InvalidArgumentException(\sprintf('Invalid bundle file path: %s', $path));
            }
        }
    }

    public function toTarGz(): string
    {
        $tarPath = tempnam(sys_get_temp_dir(), 'cloud-bkp-bundle-');
        if (false === $tarPath) {
            throw new RuntimeException('Failed to allocate temporary file for tarball.');
        }
        $tarPathTar = $tarPath.'.tar';
        try {
            $archive = new PharData($tarPathTar);
            foreach ($this->files as $relativePath => $file) {
                $archive->addFromString($this->name.'/'.$relativePath, $file->contents);
            }
            $archive->compress(Phar::GZ);
            $gzPath = $tarPathTar.'.gz';
            $bytes = file_get_contents($gzPath);
            if (false === $bytes) {
                throw new RuntimeException('Failed to read compressed bundle bytes.');
            }

            return $bytes;
        } finally {
            @unlink($tarPath);
            @unlink($tarPathTar);
            @unlink($tarPathTar.'.gz');
        }
    }

    public function writeToDirectory(string $dir, bool $force = false): string
    {
        $targetDir = rtrim($dir, '/').'/'.$this->name;

        if (is_dir($targetDir)) {
            if (!$force) {
                throw new RuntimeException(\sprintf('Bundle output directory already exists: %s (pass force=true to overwrite)', $targetDir));
            }
            $this->removeDirectory($targetDir);
        }

        if (!mkdir($targetDir, 0o700, true) && !is_dir($targetDir)) {
            throw new RuntimeException(\sprintf('Failed to create bundle directory: %s', $targetDir));
        }

        foreach ($this->files as $relativePath => $file) {
            $filePath = $targetDir.'/'.$relativePath;
            $parent = \dirname($filePath);
            if (!is_dir($parent) && !mkdir($parent, 0o700, true) && !is_dir($parent)) {
                throw new RuntimeException(\sprintf('Failed to create bundle subdir: %s', $parent));
            }
            if (false === file_put_contents($filePath, $file->contents)) {
                throw new RuntimeException(\sprintf('Failed to write bundle file: %s', $filePath));
            }
            if (!chmod($filePath, $file->mode)) {
                throw new RuntimeException(\sprintf('Failed to chmod bundle file: %s', $filePath));
            }
        }

        return $targetDir;
    }

    public function sha256(): string
    {
        return hash('sha256', $this->toTarGz());
    }

    public function tarGzFilename(): string
    {
        return $this->name.'.tar.gz';
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
