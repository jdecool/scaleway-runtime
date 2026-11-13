<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

/**
 * Builds the deployment zip: a copy of the application with production dependencies
 * and a warmed-up prod cache (ADR 0003), so Invocations never compile the container.
 */
final readonly class Packager
{
    /**
     * Patterns starting with "/" match from the application root, the others match a file or directory name anywhere.
     * vendor/ and var/ are rebuilt from scratch, the rest is local, test or secret material.
     */
    public const array EXCLUDES = [
        '/vendor', '/var', '/tests',
        '/.env.local', '/.env.local.php', '/.env.*.local',
        '/phpunit.xml', '/phpunit.xml.dist', '/.phpunit.cache', '/.phpunit.result.cache', '/.php-cs-fixer.cache',
        '.git', '.idea', '.vscode', '.DS_Store', 'node_modules',
        // Terraform state holds secrets in clear text.
        '.terraform', '*.tfstate', '*.tfstate.*',
    ];

    // https://www.scaleway.com/en/docs/serverless-functions/reference-content/functions-limitations/
    public const int MAX_ZIP_SIZE = 100 * 1024 * 1024;
    public const int MAX_CODE_SIZE = 500 * 1024 * 1024;

    /**
     * @param resource $log
     */
    public function __construct(
        private string $appDir,
        private mixed $log,
    ) {
    }

    public function package(string $zipFile): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The zip PHP extension is required to build the deployment zip.');
        }
        foreach (['handler.php' => 'run "vendor/bin/scaleway-init" first', 'composer.lock' => 'run "composer update" and commit it, builds must be reproducible'] as $file => $hint) {
            if (!is_file($this->appDir.'/'.$file)) {
                throw new \RuntimeException(\sprintf('"%s" is missing in "%s": %s.', $file, $this->appDir, $hint));
            }
        }

        $buildDir = sys_get_temp_dir().'/scaleway-package-'.bin2hex(random_bytes(4));
        try {
            $this->log('Copying the application to '.$buildDir);
            $this->copy($buildDir, $this->relativeToApp($zipFile));

            $this->log('Installing production dependencies');
            $lock = (string) file_get_contents($buildDir.'/composer.lock');
            $this->writeJson($buildDir.'/composer.lock', self::resolvePathRepositories($this->decodeJson($lock), $this->appDir));
            try {
                $this->run(['composer', 'install', '--no-dev', '--classmap-authoritative', '--no-interaction', '--no-progress'], $buildDir);
            } finally {
                // The zip ships the lock file exactly as committed.
                file_put_contents($buildDir.'/composer.lock', $lock);
            }

            if (is_file($buildDir.'/bin/console')) {
                $this->log('Warming up the prod cache');
                $this->run([\PHP_BINARY, 'bin/console', 'cache:warmup', '--no-interaction'], $buildDir);
            } else {
                $this->log('No bin/console found: the cache is not warmed up, the container will be compiled at the first Invocation');
            }

            $this->log('Writing '.$zipFile);
            [$zipSize, $codeSize] = $this->zip($buildDir, $zipFile);
            $this->log(\sprintf('Done: %.1f MiB zipped, %.1f MiB unzipped', $zipSize / 1024 ** 2, $codeSize / 1024 ** 2));
            if ($zipSize > self::MAX_ZIP_SIZE) {
                $this->log('WARNING: the zip exceeds the 100 MiB Scaleway accepts');
            }
            if ($codeSize > self::MAX_CODE_SIZE) {
                $this->log('WARNING: the unzipped code exceeds the 500 MiB Scaleway accepts');
            }
        } finally {
            $this->remove($buildDir);
        }
    }

    public static function isExcluded(string $relativePath): bool
    {
        $name = basename($relativePath);
        foreach (self::EXCLUDES as $pattern) {
            if (str_starts_with($pattern, '/') ? fnmatch($pattern, '/'.$relativePath, \FNM_PATHNAME) : fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relative "path" repositories point outside the application, so they no longer resolve from
     * the build dir: they are made absolute, against the application dir they are relative to.
     *
     * @param array<mixed> $lock
     *
     * @return array<mixed>
     */
    public static function resolvePathRepositories(array $lock, string $appDir): array
    {
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = \is_array($lock[$section] ?? null) ? $lock[$section] : [];
            foreach ($packages as $i => $package) {
                $dist = \is_array($package) ? $package['dist'] ?? null : null;
                if (\is_array($package) && \is_array($dist) && 'path' === ($dist['type'] ?? null) && \is_string($dist['url'] ?? null) && !str_starts_with($dist['url'], '/')) {
                    $dist['url'] = $appDir.'/'.$dist['url'];
                    $package['dist'] = $dist;
                    $packages[$i] = $package;
                }
            }
            if (isset($lock[$section])) {
                $lock[$section] = $packages;
            }
        }

        return $lock;
    }

    private function copy(string $buildDir, ?string $skip): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->appDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                static function (\SplFileInfo $file, string $path, \RecursiveDirectoryIterator $iterator) use ($skip): bool {
                    $relativePath = $iterator->getSubPathname();

                    return $relativePath !== $skip && !self::isExcluded($relativePath);
                },
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $this->mkdir($buildDir);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $target = $buildDir.'/'.substr($file->getPathname(), \strlen($this->appDir) + 1);
            if ($file->isDir()) {
                $this->mkdir($target);
            } elseif (!copy($file->getPathname(), $target) || !chmod($target, $file->getPerms() & 0o777)) {
                throw new \RuntimeException(\sprintf('Cannot copy "%s" to "%s".', $file->getPathname(), $target));
            }
        }
    }

    /**
     * @return array{int, int} the zip size and the total size of the zipped files
     */
    private function zip(string $buildDir, string $zipFile): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $zipFile));
        }

        $codeSize = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($buildDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $name = substr($file->getPathname(), \strlen($buildDir) + 1);
            $zip->addFile($file->getPathname(), $name);
            // Keeps the executable bits, as for bin/console.
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, ($file->getPerms() & 0o777 | 0o100000) << 16);
            $codeSize += $file->getSize();
        }

        if (!$zip->close()) {
            throw new \RuntimeException(\sprintf('Cannot write "%s": %s', $zipFile, $zip->getStatusString()));
        }
        clearstatcache(true, $zipFile);

        return [(int) filesize($zipFile), $codeSize];
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $cwd): void
    {
        $env = ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'COMPOSER_MIRROR_PATH_REPOS' => '1'] + getenv();
        // Child output goes to our stderr, keeping stdout clean like the rest of the log.
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $this->log, 2 => $this->log], $pipes, $cwd, $env);
        if (false === $process || 0 !== $exitCode = proc_close($process)) {
            throw new \RuntimeException(\sprintf('"%s" failed%s.', implode(' ', $command), isset($exitCode) ? ' with exit code '.$exitCode : ''));
        }
    }

    private function relativeToApp(string $file): ?string
    {
        $dir = realpath(\dirname($file));

        return false !== $dir && str_starts_with($dir.'/', $this->appDir.'/') ? ltrim(substr($dir, \strlen($this->appDir)).'/'.basename($file), '/') : null;
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o777, true)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $dir));
        }
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : throw new \UnexpectedValueException('composer.lock must hold a JSON object.');
    }

    /**
     * @param array<mixed> $data
     */
    private function writeJson(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    private function log(string $message): void
    {
        fwrite($this->log, '> '.$message.\PHP_EOL);
    }
}
