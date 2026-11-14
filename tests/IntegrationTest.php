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
        self::assertIsString($response['body']);
        // The CLI error renderer Symfony picks outside a web SAPI would dump the exception, even in prod.
        self::assertStringNotContainsString('NotFoundHttpException', $response['body']);
    }

    public function testMultipartUploadReachesTheController(): void
    {
        [$response] = $this->invoke([[
            'path' => '/upload',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=b'],
            'body' => base64_encode("--b\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nReport\r\n"
                ."--b\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"report.csv\"\r\nContent-Type: text/csv\r\n\r\na,b\n1,2\r\n--b--\r\n"),
            'isBase64Encoded' => true,
        ]]);

        self::assertSame(200, $response['statusCode']);
        self::assertSame(['title' => 'Report', 'name' => 'report.csv', 'content' => "a,b\n1,2"], $this->json($response));
        self::assertSame([], glob($this->dir.'/tmp/php*'), 'Uploaded files must be deleted after the Invocation.');
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

    public function testResponseFrontControllerIsCalledForEveryInvocationWithItsRequest(): void
    {
        [$first, $second] = $this->invoke([
            ['path' => '/', 'queryStringParameters' => ['name' => 'Ada'], 'headers' => ['X-Forwarded-Proto' => 'https']],
            ['path' => '/', 'queryStringParameters' => ['name' => 'Grace']],
        ], ['front_controller' => 'public/hello.php']);

        self::assertSame(200, $first['statusCode']);
        self::assertIsArray($first['headers']);
        self::assertSame('application/json', $first['headers']['Content-Type'] ?? null);
        $first = $this->json($first);
        $second = $this->json($second);

        self::assertSame(['message' => 'Hello Ada!', 'secure' => true, 'env' => 'prod', 'calls' => 1], array_diff_key($first, ['pid' => 0]));
        self::assertSame(['message' => 'Hello Grace!', 'secure' => false, 'env' => 'prod', 'calls' => 2], array_diff_key($second, ['pid' => 0]));
        self::assertSame($first['pid'], $second['pid']);
    }

    public function testFunctionWithoutAppEnvRunsProdDespiteTheDotenvFile(): void
    {
        [$response] = $this->invoke([['path' => '/']], ['front_controller' => 'public/hello.php'], []);

        self::assertSame('prod', $this->json($response)['env']);
    }

    public function testRawCallableIsBuiltOnceAndGetsEveryEventUntouchedCronIncluded(): void
    {
        $cron = ['method' => 'POST', 'path' => '/', 'body' => '{"command":"app:count","secret":"cron-secret"}'];
        $http = ['path' => '/state', 'method' => 'GET', 'headers' => ['Host' => 'internal.local'], 'isBase64Encoded' => false];

        [$first, $second] = $this->invoke([$http, $cron], ['front_controller' => 'public/raw.php']);

        self::assertSame(['Content-Type' => 'application/json'], $first['headers']);
        $first = $this->json($first);
        $second = $this->json($second);
        self::assertSame(['event' => $http, 'calls' => 1], array_diff_key($first, ['pid' => 0]));
        self::assertSame(['event' => $cron, 'calls' => 2], array_diff_key($second, ['pid' => 0]), 'A raw callable gets Command Invocations as they are.');
        self::assertSame($first['pid'], $second['pid']);
    }

    public function testRequestHandlerServesInvocationsThroughThePsr7Bridge(): void
    {
        [$first, $second] = $this->invoke([
            [
                'path' => '/upload',
                'method' => 'POST',
                'headers' => [
                    'Host' => 'internal.local',
                    'Content-Type' => 'multipart/form-data; boundary=b',
                    'X-Forwarded-For' => '203.0.113.7',
                    'X-Forwarded-Host' => 'api.example.com',
                    'X-Forwarded-Proto' => 'https',
                ],
                'body' => base64_encode("--b\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nReport\r\n"
                    ."--b\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"report.csv\"\r\n\r\na,b\r\n--b--\r\n"),
                'isBase64Encoded' => true,
            ],
            ['path' => '/', 'headers' => ['Host' => 'internal.local']],
        ], ['front_controller' => 'public/request-handler.php']);

        self::assertSame(201, $first['statusCode']);
        self::assertIsArray($first['headers']);
        self::assertSame(['a', 'b'], $first['headers']['X-Multi'] ?? null);
        self::assertSame(['first=1; path=/', 'second=2; path=/'], $first['headers']['Set-Cookie'] ?? null);
        $first = $this->json($first);
        $second = $this->json($second);

        self::assertSame('https://api.example.com/upload', $first['uri']);
        self::assertSame('203.0.113.7', $first['remoteAddr']);
        self::assertSame(['title' => 'Report'], $first['parsedBody']);
        self::assertSame(['doc' => ['name' => 'report.csv', 'content' => 'a,b']], $first['files']);
        self::assertSame([], glob($this->dir.'/tmp/php*'), 'Uploaded files must be deleted after the Invocation.');
        self::assertSame(['uri' => 'http://internal.local/', 'remoteAddr' => '127.0.0.1', 'handled' => 2, 'resets' => 1], array_intersect_key($second, ['uri' => 0, 'remoteAddr' => 0, 'handled' => 0, 'resets' => 0]));
        self::assertSame($first['pid'], $second['pid']);
    }

    public function testCommandInvocationsRunThroughTheKernelAndShareNoStateWithHttpInvocations(): void
    {
        $command = static fn (array $args, string $secret = 'cron-secret'): array => [
            'path' => '/',
            'method' => 'POST',
            'body' => json_encode(['command' => 'app:count', 'args' => $args, 'secret' => $secret], \JSON_THROW_ON_ERROR),
        ];
        [$http, $first, $second, $httpAfter, $third, $forged] = $this->invoke([
            ['path' => '/state'],
            $command(['--times' => '2']),
            $command([]),
            ['path' => '/state'],
            $command([]),
            $command([], 'guess'),
        ]);

        self::assertSame(1, $this->json($http)['count']);
        self::assertSame(200, $first['statusCode']);
        self::assertIsString($first['body']);
        self::assertStringStartsWith('count=2 kernelInstances=1 ', $first['body'], 'The command must start from reset services.');
        self::assertIsString($second['body']);
        self::assertStringStartsWith('count=1 kernelInstances=1 ', $second['body'], 'The Console Application must reset services after a command.');
        self::assertSame(1, $this->json($httpAfter)['count'], 'A command must leave no state to the next HTTP Invocation.');
        self::assertSame(1, $this->json($httpAfter)['kernelInstances']);
        self::assertIsString($third['body']);
        self::assertStringStartsWith('count=1 ', $third['body'], 'An HTTP Invocation must leave no state to the next command.');
        self::assertSame(404, $forged['statusCode'], 'A body with a wrong secret is a plain HTTP POST.');
    }

    public function testConsoleFrontControllerOfAKernelRunsCommandsOnly(): void
    {
        [$first, $second, $http] = $this->invoke([
            ['method' => 'POST', 'body' => '{"command":"app:count","args":{"--times":"3"},"secret":"cron-secret"}'],
            ['method' => 'POST', 'body' => '{"command":"app:count","secret":"cron-secret"}'],
            ['path' => '/state'],
        ], ['front_controller' => 'bin/console.php']);

        self::assertSame(200, $first['statusCode']);
        self::assertIsString($first['body']);
        self::assertStringStartsWith('count=3 kernelInstances=1 ', $first['body']);
        self::assertIsString($second['body']);
        self::assertStringStartsWith('count=1 kernelInstances=1 ', $second['body'], 'Services must be reset after each command.');
        self::assertSame(403, $http['statusCode']);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed>       $options runtime options on top of "project_dir"
     * @param array<string, string>      $appEnv  the APP_* variables of the function's environment
     *
     * @return array<array<mixed>>
     */
    private function invoke(array $events, array $options = [], array $appEnv = ['APP_ENV' => 'prod', 'APP_DEBUG' => '0']): array
    {
        $driver = 'require $argv[1]; echo json_encode(array_map(fn ($event) => handle($event, []), json_decode(stream_get_contents(STDIN), true)));';
        $process = proc_open(
            [\PHP_BINARY, '-r', $driver, $this->projectDir.'/handler.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $appEnv + [
                'APP_RUNTIME_OPTIONS' => json_encode(['project_dir' => $this->projectDir] + $options, \JSON_THROW_ON_ERROR),
                'TMPDIR' => $this->dir.'/tmp',
                'SCALEWAY_CRON_SECRET' => 'cron-secret',
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
