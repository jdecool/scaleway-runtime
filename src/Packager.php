<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

/**
 * Builds the deployment zip: the application sources with composer.json and composer.lock, from which Scaleway
 * installs the dependencies at deploy time (see docs/adr/0008-let-scaleway-install-the-dependencies.md).
 */
final readonly class Packager
{
    /**
     * Patterns starting with "/" match from the application root, the others match a file or directory name anywhere.
     * Scaleway installs vendor/ itself and the cache is built on the platform, the rest is local, test or secret material.
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
        foreach (['handler.php' => 'run "vendor/bin/scaleway-init" first', 'composer.lock' => 'run "composer update" and commit it, Scaleway installs the dependencies from it'] as $file => $hint) {
            if (!is_file($this->appDir.'/'.$file)) {
                throw new \RuntimeException(\sprintf('"%s" is missing in "%s": %s.', $file, $this->appDir, $hint));
            }
        }
        self::checkLock((string) file_get_contents($this->appDir.'/composer.json'), (string) file_get_contents($this->appDir.'/composer.lock'));

        $this->log('Writing '.$zipFile);
        [$zipSize, $codeSize] = $this->zip($zipFile, $this->relativeToApp($zipFile));
        $this->log(\sprintf('Done: %.1f MiB zipped, %.1f MiB unzipped before Scaleway installs the dependencies', $zipSize / 1024 ** 2, $codeSize / 1024 ** 2));
        if ($zipSize > self::MAX_ZIP_SIZE) {
            $this->log('WARNING: the zip exceeds the 100 MiB Scaleway accepts');
        }
        if ($codeSize > self::MAX_CODE_SIZE) {
            $this->log('WARNING: the unzipped code exceeds the 500 MiB Scaleway accepts');
        }
    }

    /**
     * Refuses what would make Scaleway's "composer install" fail or install something else than the lock says:
     * Composer only warns about a stale lock, and a failed install does not even fail the deployment.
     */
    public static function checkLock(string $composerJson, string $composerLock): void
    {
        $composer = self::decodeJson($composerJson, 'composer.json');
        $lock = self::decodeJson($composerLock, 'composer.lock');

        foreach (\is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [] as $repository) {
            if (\is_array($repository) && 'path' === ($repository['type'] ?? null)) {
                throw new \RuntimeException(\sprintf('composer.json declares the "path" repository "%s", which Scaleway cannot resolve: remove it and run "composer update".', \is_string($repository['url'] ?? null) ? $repository['url'] : ''));
            }
        }
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (\is_array($package) && \is_array($package['dist'] ?? null) && 'path' === ($package['dist']['type'] ?? null)) {
                    throw new \RuntimeException(\sprintf('composer.lock installs "%s" from a local path, which Scaleway cannot resolve: install it from a remote repository and run "composer update".', \is_string($package['name'] ?? null) ? $package['name'] : ''));
                }
            }
        }
        if (($lock['content-hash'] ?? null) !== self::contentHash($composer)) {
            throw new \RuntimeException('composer.lock is not up to date with composer.json: run "composer update --lock" and commit it.');
        }
    }

    /**
     * Composer's own Locker::getContentHash(), as the application may not ship Composer's classes.
     *
     * @param array<mixed> $composer
     */
    public static function contentHash(array $composer): string
    {
        $relevant = array_intersect_key($composer, array_flip(['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
        $config = \is_array($composer['config'] ?? null) ? $composer['config'] : [];
        $relevantConfig = [];
        foreach (['platform', 'policy'] as $key) {
            if (isset($config[$key])) {
                $relevantConfig[$key] = $config[$key];
            }
        }
        if ([] !== $relevantConfig) {
            $relevant['config'] = $relevantConfig;
        }
        ksort($relevant);

        return md5(json_encode($relevant, \JSON_THROW_ON_ERROR));
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
     * @return array{int, int} the zip size and the total size of the zipped files
     */
    private function zip(string $zipFile, ?string $skip): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->appDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                static function (\SplFileInfo $file, string $path, \RecursiveDirectoryIterator $iterator) use ($skip): bool {
                    $relativePath = $iterator->getSubPathname();

                    return $relativePath !== $skip && !self::isExcluded($relativePath);
                },
            ),
        );

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $zipFile));
        }
        $codeSize = 0;
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $name = substr($file->getPathname(), \strlen($this->appDir) + 1);
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

    private function relativeToApp(string $file): ?string
    {
        $dir = realpath(\dirname($file));

        return false !== $dir && str_starts_with($dir.'/', $this->appDir.'/') ? ltrim(substr($dir, \strlen($this->appDir)).'/'.basename($file), '/') : null;
    }

    /**
     * @return array<mixed>
     */
    private static function decodeJson(string $json, string $file): array
    {
        $data = json_decode($json, true);

        return \is_array($data) ? $data : throw new \UnexpectedValueException(\sprintf('%s must hold a JSON object.', $file));
    }

    private function log(string $message): void
    {
        fwrite($this->log, '> '.$message.\PHP_EOL);
    }
}
