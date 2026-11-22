<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;

/**
 * Checks the Platform Requirements Scaleway's "composer install --no-dev" checks against a Function Runtime of the
 * catalogue: an unmet one fails that install silently, and every Invocation then answers a 500.
 * See docs/adr/0013-check-platform-requirements-against-an-explicit-function-runtime.md.
 *
 * @phpstan-import-type FunctionRuntime from FunctionRuntimeCatalogue
 */
final readonly class Doctor
{
    // Composer's PlatformRepository::PLATFORM_PACKAGE_REGEX: any other name is a package, not a Platform Requirement.
    private const string PLATFORM_PACKAGE = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer(?:-(?:plugin|runtime)-api)?)$}iD';

    // Scaleway installs with Composer 2, whose APIs every package of the ecosystem accepts.
    private const array IGNORED = ['composer', 'composer-plugin-api', 'composer-runtime-api'];

    private const string SATISFIED = 'SATISFIED';
    private const string INCOMPATIBLE = 'INCOMPATIBLE';
    private const string UNKNOWN = 'UNKNOWN';

    private VersionParser $parser;

    /**
     * @param resource $log
     */
    public function __construct(
        private FunctionRuntimeCatalogue $catalogue,
        private mixed $log,
    ) {
        $this->parser = new VersionParser();
    }

    /**
     * Writes the report to the log.
     *
     * @return bool false when a Platform Requirement is incompatible
     */
    public function check(string $appDir, string $functionRuntime): bool
    {
        $target = $this->catalogue->get($functionRuntime);
        foreach (['composer.json' => 'run "composer init"', 'composer.lock' => 'run "composer update" and commit it, Scaleway installs the dependencies from it'] as $file => $hint) {
            if (!is_file($appDir.'/'.$file)) {
                throw new \RuntimeException(\sprintf('"%s" is missing in "%s": %s.', $file, $appDir, $hint));
            }
        }
        $composerJson = (string) file_get_contents($appDir.'/composer.json');
        $composerLock = (string) file_get_contents($appDir.'/composer.lock');
        Packager::checkLock($composerJson, $composerLock);
        /** @var array<mixed> $composer checked by checkLock() */
        $composer = json_decode($composerJson, true);
        /** @var array<mixed> $lock checked by checkLock() */
        $lock = json_decode($composerLock, true);

        $packages = array_values(array_filter(\is_array($lock['packages'] ?? null) ? $lock['packages'] : [], \is_array(...)));
        $providers = $this->providers($composer, $packages);

        $counts = [self::SATISFIED => 0, self::UNKNOWN => 0, self::INCOMPATIBLE => 0];
        $rows = [];
        foreach ($this->requirements($lock, $packages) as [$declarer, $name, $constraint]) {
            $required = $this->constraint($constraint, $declarer);
            $outcome = $this->outcome($name, $required, $target, $providers);
            ++$counts[$outcome];
            if (self::SATISFIED === $outcome) {
                continue;
            }
            $rows[] = [$outcome, $name.' '.$constraint, 'required by '.$declarer, $this->targetValue($functionRuntime, $target, $name, $outcome)];
            if (self::INCOMPATIBLE === $outcome) {
                $rows[] = ['', $this->suggestion($functionRuntime, $name, $required, $providers)];
            }
        }

        $this->report($rows);
        $this->write(\sprintf('%d satisfied, %d unknown, %d incompatible on %s (measured %s)', $counts[self::SATISFIED], $counts[self::UNKNOWN], $counts[self::INCOMPATIBLE], $functionRuntime, self::date($target)));
        $this->write('This covers declared Platform Requirements only, not whether the application runs.');

        return 0 === $counts[self::INCOMPATIBLE];
    }

    /**
     * What Composer checks when installing a lock without its dev packages: the root requirements it recorded under
     * "platform", and the requirements of every package it installs.
     *
     * @param array<mixed>       $lock
     * @param list<array<mixed>> $packages
     *
     * @return list<array{string, string, string}> declarer, lowercase name and constraint of each Platform Requirement
     */
    private function requirements(array $lock, array $packages): array
    {
        $requirements = [];
        $add = static function (string $declarer, mixed $links) use (&$requirements): void {
            foreach (\is_array($links) ? $links : [] as $name => $constraint) {
                $name = strtolower((string) $name);
                if (\is_string($constraint) && 1 === preg_match(self::PLATFORM_PACKAGE, $name) && !\in_array($name, self::IGNORED, true)) {
                    $requirements[] = [$declarer, $name, $constraint];
                }
            }
        };
        $add('the application', $lock['platform'] ?? null);
        foreach ($packages as $package) {
            $add(self::name($package), $package['require'] ?? null);
        }

        return $requirements;
    }

    /**
     * @param array<mixed>       $composer
     * @param list<array<mixed>> $packages
     *
     * @return array<string, list<ConstraintInterface>> what the application and its packages provide or replace, by name
     */
    private function providers(array $composer, array $packages): array
    {
        $providers = [];
        foreach ([['the application', $composer], ...array_map(static fn (array $package): array => [self::name($package), $package], $packages)] as [$declarer, $package]) {
            foreach (['provide', 'replace'] as $section) {
                foreach (\is_array($package[$section] ?? null) ? $package[$section] : [] as $name => $constraint) {
                    if (!\is_string($constraint)) {
                        continue;
                    }
                    // The root package has no version of its own here: its "self.version" links match any.
                    $providers[strtolower((string) $name)][] = 'self.version' === $constraint
                        ? (\is_string($package['version'] ?? null) ? $this->constraint('=='.$package['version'], $declarer) : new MatchAllConstraint())
                        : $this->constraint($constraint, $declarer);
                }
            }
        }

        return $providers;
    }

    /**
     * @param FunctionRuntime                          $target
     * @param array<string, list<ConstraintInterface>> $providers
     */
    private function outcome(string $name, ConstraintInterface $required, array $target, array $providers): string
    {
        foreach ($providers[$name] ?? [] as $provided) {
            if ($required->matches($provided)) {
                return self::SATISFIED;
            }
        }
        $version = match (true) {
            'php' === $name => $target['php'],
            str_starts_with($name, 'ext-') => $target['extensions'][$name] ?? null,
            // lib-*, php-64bit and the like are not measured.
            default => false,
        };
        if (false === $version) {
            return self::UNKNOWN;
        }

        return null !== $version && $required->matches(new Constraint('==', $this->parser->normalize($version))) ? self::SATISFIED : self::INCOMPATIBLE;
    }

    /**
     * @param FunctionRuntime $target
     */
    private function targetValue(string $functionRuntime, array $target, string $name, string $outcome): string
    {
        if (self::UNKNOWN === $outcome) {
            return 'not in the Function Runtime Catalogue';
        }
        $version = 'php' === $name ? $target['php'] : ($target['extensions'][$name] ?? null);

        return \sprintf('%s: %s (measured %s)', $functionRuntime, $version ?? 'not loaded', self::date($target));
    }

    /**
     * @param array<string, list<ConstraintInterface>> $providers
     */
    private function suggestion(string $functionRuntime, string $name, ConstraintInterface $required, array $providers): string
    {
        $others = array_keys(array_filter(
            $this->catalogue->functionRuntimes,
            fn (array $other, string $otherName): bool => $otherName !== $functionRuntime && self::SATISFIED === $this->outcome($name, $required, $other, $providers),
            \ARRAY_FILTER_USE_BOTH,
        ));
        $suggestion = [] === $others
            ? 'No measured Function Runtime meets it: use Serverless Containers for custom extensions or versions.'
            : \sprintf('%s %s it: deploy on %s, or use Serverless Containers for custom extensions or versions.', implode(', ', $others), 1 === \count($others) ? 'meets' : 'meet', implode(' or ', $others));

        // The catalogue may predate a PHP upgrade Scaleway made since.
        return 'php' === $name ? $suggestion.' If Scaleway has upgraded PHP since the measurement, update jdecool/scaleway-runtime or re-run its probe.' : $suggestion;
    }

    private function constraint(string $constraint, string $declarer): ConstraintInterface
    {
        try {
            return $this->parser->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            throw new \UnexpectedValueException(\sprintf('The invalid constraint "%s" is declared by %s: %s', $constraint, $declarer, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function report(array $rows): void
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (\array_slice($row, 0, -1) as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, \strlen($cell));
            }
        }
        foreach ($rows as $row) {
            $last = array_pop($row);
            $this->write(implode('', array_map(static fn (string $cell, int $column): string => str_pad($cell, $widths[$column] + 2), $row, array_keys($row))).$last);
        }
        if ([] !== $rows) {
            $this->write('');
        }
    }

    /**
     * @param array<mixed> $package
     */
    private static function name(array $package): string
    {
        return trim((\is_string($package['name'] ?? null) ? $package['name'] : 'a package').' '.(\is_string($package['version'] ?? null) ? $package['version'] : ''));
    }

    /**
     * @param FunctionRuntime $target
     */
    private static function date(array $target): string
    {
        return substr($target['measuredAt'], 0, 10);
    }

    private function write(string $line): void
    {
        fwrite($this->log, $line.\PHP_EOL);
    }
}
