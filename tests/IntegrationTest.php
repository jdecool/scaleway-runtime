<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs a real MicroKernelTrait Kernel through the committed handler stub, in a separate PHP process
 * that loads handler.php once and calls handle() per Event, as Scaleway does.
 */
final class IntegrationTest extends TestCase
{
    private Filesystem $filesystem;
    private string $dir;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->dir = sys_get_temp_dir().'/scaleway-runtime-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->dir.'/project';

        $this->filesystem->mirror(__DIR__.'/Fixtures/App', $this->projectDir);
        $this->filesystem->copy(__DIR__.'/../resources/handler.php', $this->projectDir.'/handler.php');
        $this->filesystem->dumpFile(
            $this->projectDir.'/vendor/autoload.php',
            '<?php return require '.var_export(\dirname(__DIR__).'/vendor/autoload.php', true).';',
        );
        $this->filesystem->mkdir($this->dir.'/tmp');
    }

    protected function tearDown(): void
    {
        $this->filesystem->chmod($this->dir, 0o777, 0o000, true);
        $this->filesystem->remove($this->dir);
    }

    public function testConsecutiveInvocationsReuseTheKernelAndResetServices(): void
    {
        [$first, $second] = $this->invoke([
            [
                'path' => '/state',
                'method' => 'GET',
                'queryStringParameters' => ['page' => '2'],
                'headers' => ['Host' => 'internal.local', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'],
                'body' => '',
                'isBase64Encoded' => false,
            ],
            ['path' => '/state', 'method' => 'GET', 'headers' => ['Host' => 'internal.local']],
        ]);

        self::assertSame(200, $first['statusCode']);
        self::assertSame(200, $second['statusCode']);
        $first = $this->json($first);
        $second = $this->json($second);

        self::assertSame(1, $first['count']);
        self::assertSame(1, $second['count'], 'The Counter service must be reset between Invocations.');
        self::assertSame(1, $second['kernelInstances'], 'The Kernel must be reused across Invocations.');
        self::assertSame($first['pid'], $second['pid']);
        self::assertTrue($first['secure']);
        self::assertSame('203.0.113.7', $first['clientIp']);
        self::assertSame(['page' => '2'], $first['query']);
        self::assertSame($this->projectDir.'/var/cache/prod', $first['cacheDir']);
    }

    public function testUnknownRouteIsHandledByTheKernel(): void
    {
        [$response] = $this->invoke([['path' => '/missing', 'method' => 'GET']]);

        self::assertSame(404, $response['statusCode']);
    }

    public function testReadOnlyProjectUsesTheWarmedBuildDirAndATempCacheDir(): void
    {
        $this->invoke([['path' => '/state']]);
        $this->filesystem->chmod($this->projectDir, 0o555, 0o000, true);

        [$response] = $this->invoke([['path' => '/state']]);

        self::assertSame(200, $response['statusCode']);
        $state = $this->json($response);
        self::assertSame($this->dir.'/tmp/cache/prod', $state['cacheDir']);
        self::assertSame($this->projectDir.'/var/cache/prod', $state['buildDir']);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<array<mixed>>
     */
    private function invoke(array $events): array
    {
        $driver = 'require $argv[1]; echo json_encode(array_map(fn ($event) => handle($event, []), json_decode(stream_get_contents(STDIN), true)));';
        $process = proc_open(
            [\PHP_BINARY, '-r', $driver, $this->projectDir.'/handler.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'APP_RUNTIME_OPTIONS' => json_encode(['project_dir' => $this->projectDir], \JSON_THROW_ON_ERROR),
                'TMPDIR' => $this->dir.'/tmp',
                'PATH' => getenv('PATH') ?: '',
            ],
        );
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($events, \JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $output.$errors);
        $responses = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($responses, $errors);

        return array_map(static function (mixed $response): array {
            self::assertIsArray($response);

            return $response;
        }, $responses);
    }

    /**
     * @return array<mixed>
     */
    private function json(mixed $response): array
    {
        self::assertIsArray($response);
        self::assertIsString($response['body']);
        $data = json_decode($response['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
