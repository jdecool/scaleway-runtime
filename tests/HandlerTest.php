<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\CommandInvocation;
use JDecool\ScalewayRuntime\Handler;
use JDecool\ScalewayRuntime\Tests\Fixtures\RecordingKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HandlerTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/Fixtures';

    /** @var array<mixed> */
    private array $server;
    /** @var array<mixed> */
    private array $env;
    private int $umask;
    /** @var resource */
    private $errorLog;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->env = $_ENV;
        // GenericRuntime resets the umask in debug mode.
        $this->umask = umask();
        $errorLog = fopen('php://memory', 'w+');
        self::assertNotFalse($errorLog);
        $this->errorLog = $errorLog;
        RecordingKernel::reset();
        $_SERVER[CommandInvocation::SECRET_VAR] = 's3cret';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->env;
        umask($this->umask);
        Request::setTrustedProxies([], 0);
    }

    public function testApplicationIsBuiltOnceAndTerminatedAfterEachInvocation(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php']);

        self::assertSame('handled #1 /a', $handler->invoke(['path' => '/a'], [])['body']);
        self::assertSame('handled #2 /b', $handler->invoke(['path' => '/b'], [])['body']);
        self::assertSame(1, RecordingKernel::$builds);
        self::assertSame(2, RecordingKernel::$last?->terminated);
    }

    public function testFailedBuildIsRetriedOnNextInvocation(): void
    {
        RecordingKernel::$failingBuilds = 1;
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php', 'debug' => false]);

        $first = $handler->invoke(['path' => '/'], []);
        self::assertSame(500, $first['statusCode']);
        self::assertStringContainsString('Build failed on purpose.', $this->errorLog());

        self::assertSame('handled #1 /', $handler->invoke(['path' => '/'], [])['body']);
        self::assertSame(2, RecordingKernel::$builds);
    }

    public function testEscapedErrorIsGenericWithoutDebug(): void
    {
        $response = $this->handler(['front_controller' => 'front-controllers/recording.php', 'debug' => false])
            ->invoke(['path' => '/throw'], []);

        self::assertSame([
            'statusCode' => 500,
            'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
            'body' => 'Internal Server Error',
        ], $response);
        self::assertStringContainsString('Escaped on purpose.', $this->errorLog());
    }

    public function testEscapedErrorIsDetailedInDebug(): void
    {
        $response = $this->handler(['front_controller' => 'front-controllers/recording.php', 'debug' => true])
            ->invoke(['path' => '/throw'], []);

        self::assertSame(500, $response['statusCode']);
        self::assertStringContainsString('LogicException: Escaped on purpose.', $response['body']);
    }

    public function testApplicationKeepsServingAfterAnEscapedError(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php', 'debug' => false]);

        $handler->invoke(['path' => '/throw'], []);

        self::assertSame('handled #1 /', $handler->invoke(['path' => '/'], [])['body']);
        self::assertSame(1, RecordingKernel::$builds);
    }

    public function testStaticFilesAreServedWithoutBuildingTheApplication(): void
    {
        RecordingKernel::$failingBuilds = 1;
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php', 'debug' => false]);

        $response = $handler->invoke(['path' => '/static.txt', 'httpMethod' => 'GET'], []);

        self::assertSame(200, $response['statusCode']);
        self::assertSame("Served without the Application.\n", $response['body']);
        self::assertSame(0, RecordingKernel::$builds);
        // Only another method reaches the Application, whose build fails once on purpose.
        self::assertSame(500, $handler->invoke(['path' => '/static.txt', 'httpMethod' => 'POST'], [])['statusCode']);
        self::assertSame(1, RecordingKernel::$builds);
    }

    public function testInvalidStaticCompressionFailsEveryInvocation(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php', 'debug' => false, 'static_compression' => 'deflate']);

        self::assertSame(500, $handler->invoke(['path' => '/static.txt'], [])['statusCode']);
        self::assertSame(500, $handler->invoke(['path' => '/'], [])['statusCode']);
        self::assertStringContainsString('The "static_compression" runtime option must be false, "gzip" or "br"', $this->errorLog());
        self::assertSame(0, RecordingKernel::$builds);
    }

    public function testUnsupportedApplicationTypeIsReported(): void
    {
        $this->handler(['front_controller' => 'front-controllers/unsupported.php', 'debug' => false])->invoke([], []);

        self::assertStringContainsString('cannot run Applications of type "stdClass"', $this->errorLog());
    }

    public function testResponseFrontControllerIsCalledForEveryInvocation(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/response.php']);

        self::assertSame('call #1', $handler->invoke([], [])['body']);
        self::assertSame('call #2', $handler->invoke([], [])['body']);
    }

    public function testResponseFrontControllerGetsTheRequestOfEachInvocation(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/request-response.php']);

        $first = $handler->invoke(['queryStringParameters' => ['name' => 'Ada'], 'headers' => ['X-Real-Ip' => '203.0.113.7']], []);
        $second = $handler->invoke(['queryStringParameters' => ['name' => 'Grace']], []);

        self::assertSame('Hello Ada from 203.0.113.7', $first['body']);
        self::assertSame('Hello Grace from 127.0.0.1', $second['body']);
    }

    public function testHostOptionIsTheHostOfEveryRequest(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php', 'host' => 'app.example.com']);
        $handler->invoke(['path' => '/', 'headers' => ['X-Forwarded-Proto' => 'https', 'Host' => 'spoofed.example']], []);

        self::assertSame('https://app.example.com/', RecordingKernel::$last?->lastRequest?->getUri());
    }

    public function testHostDefaultsToTheScalewayHostVariable(): void
    {
        $_SERVER['SCALEWAY_HOST'] = 'fn.example.com';
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php']);
        $handler->invoke(['path' => '/'], []);

        self::assertSame('fn.example.com', RecordingKernel::$last?->lastRequest?->getHost());
    }

    public function testResponseFrontControllerReturningSomethingElseFailsTheInvocation(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/request-response.php', 'debug' => false]);

        self::assertSame(500, $handler->invoke(['path' => '/nothing'], [])['statusCode']);
        self::assertStringContainsString('must return one for every Invocation, "null" returned', $this->errorLog());
        self::assertSame(200, $handler->invoke(['path' => '/'], [])['statusCode']);
    }

    public function testRequestHandlerIsBuiltOnceAndKeepsServingAfterAnEscapedError(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/request-handler.php', 'debug' => false]);

        $first = $handler->invoke(['path' => '/'], []);
        self::assertSame(500, $handler->invoke(['path' => '/throw'], [])['statusCode']);
        self::assertStringContainsString('Escaped on purpose.', $this->errorLog());
        $third = $handler->invoke(['path' => '/'], []);

        self::assertSame(201, $first['statusCode']);
        self::assertSame(3, $this->decode($third)['handled'], 'The request handler must be reused across Invocations.');
    }

    public function testConsoleApplicationRunsCommandInvocationsWithTheirArgs(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/console.php']);

        $response = $handler->invoke($this->commandEvent('app:greet', ['name' => 'Ada', '--tag' => ['a', 'b']]), []);

        self::assertSame(200, $response['statusCode']);
        self::assertSame('text/plain; charset=UTF-8', $response['headers']['Content-Type'] ?? null);
        self::assertSame("Hello Ada [a,b]\n", $response['body'], 'Output must be captured undecorated.');
        self::assertSame('', $this->errorLog());
    }

    public function testCommandInvocationsAreNotInteractive(): void
    {
        $response = $this->handler(['front_controller' => 'front-controllers/console.php'])->invoke($this->commandEvent('app:greet'), []);

        self::assertSame(200, $response['statusCode']);
        self::assertStringContainsString('Hello nobody', $response['body']);
    }

    public function testNonZeroExitCodeIsA500WithTheOutputAndIsLogged(): void
    {
        $response = $this->handler(['front_controller' => 'front-controllers/console.php'])
            ->invoke($this->commandEvent('app:greet', ['name' => 'Ada', '--fail' => true]), []);

        self::assertSame(500, $response['statusCode']);
        self::assertSame("Hello Ada []\n", $response['body']);
        self::assertStringContainsString('Command "app:greet" failed with exit code 3: Hello Ada []', $this->errorLog());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failingCommands(): iterable
    {
        yield 'exception' => ['app:throw', 'Thrown on purpose.'];
        yield 'error' => ['app:error', 'Error on purpose.'];
        yield 'unknown command' => ['app:missing', 'Command "app:missing" is not defined.'];
    }

    #[DataProvider('failingCommands')]
    public function testFailingCommandIsRenderedInA500AndTheProcessKeepsServing(string $command, string $message): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/console.php', 'debug' => false]);

        $response = $handler->invoke($this->commandEvent($command), []);

        self::assertSame(500, $response['statusCode']);
        self::assertStringContainsString($message, $response['body']);
        self::assertStringContainsString($message, $this->errorLog());
        self::assertSame(200, $handler->invoke($this->commandEvent('app:greet', ['name' => 'Ada']), [])['statusCode']);
    }

    public function testConsoleApplicationForbidsHttpInvocations(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/console.php']);

        self::assertSame(403, $handler->invoke(['path' => '/'], [])['statusCode']);
        $forged = $handler->invoke(['method' => 'POST', 'path' => '/', 'body' => '{"command":"app:greet","secret":"guess"}'], []);
        self::assertSame(403, $forged['statusCode']);
        self::assertStringContainsString('Command Invocation rejected', $this->errorLog());
    }

    public function testFailedResetAfterACommandKeepsItsResponseAndIsLogged(): void
    {
        $response = $this->handler(['front_controller' => 'front-controllers/console-reset-fails.php'])->invoke($this->commandEvent('app:ok'), []);

        self::assertSame(200, $response['statusCode']);
        self::assertStringContainsString('Service reset failed: LogicException: Reset failed on purpose.', $this->errorLog());
    }

    public function testFailedKernelBootOfAConsoleFrontControllerIsRetriedOnNextInvocation(): void
    {
        RecordingKernel::$failingBuilds = 1;
        $handler = $this->handler(['front_controller' => 'front-controllers/console-boot-fails.php', 'debug' => false]);

        self::assertSame(500, $handler->invoke($this->commandEvent('list'), [])['statusCode']);
        self::assertStringContainsString('Boot failed on purpose.', $this->errorLog());
        self::assertSame(200, $handler->invoke($this->commandEvent('list'), [])['statusCode']);
    }

    public function testCommandFrontControllerRunsItsCommandWhateverTheName(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/command.php']);

        $first = $handler->invoke($this->commandEvent('anything', ['name' => 'Ada', '--yell' => true]), []);
        $second = $handler->invoke($this->commandEvent('front-controllers/command.php', ['name' => 'Grace']), []);

        self::assertSame(['statusCode' => 200, 'body' => 'front-controllers/command.php: hello ADA'], array_intersect_key($first, ['statusCode' => 0, 'body' => 0]));
        self::assertSame('front-controllers/command.php: hello Grace', $second['body']);
        self::assertSame(403, $handler->invoke(['path' => '/'], [])['statusCode']);
    }

    public function testCommandBodyWithoutTheSecretReachesTheHttpApplication(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php']);

        $response = $handler->invoke(['method' => 'POST', 'path' => '/', 'body' => '{"command":"cache:clear"}'], []);

        self::assertSame('handled #1 /', $response['body']);
    }

    public function testCommandInvocationOfAnApplicationWithoutConsoleFails(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/recording.php', 'debug' => false]);

        self::assertSame(500, $handler->invoke($this->commandEvent('app:greet'), [])['statusCode']);
        self::assertStringContainsString('Command Invocations need a Front Controller returning a Kernel, a Console Application or a Command, not "'.RecordingKernel::class.'"', $this->errorLog());
        self::assertSame('handled #1 /', $handler->invoke(['path' => '/'], [])['body']);
    }

    public function testRawCallableIsBuiltOnceAndGetsEveryEventAndContextUntouched(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/raw.php']);
        $event = ['path' => '/', 'httpMethod' => 'GET', 'headers' => ['X-Forwarded-For' => '203.0.113.7'], 'body' => 'raw'];

        $first = $handler->invoke($event, ['memoryLimitInMb' => 128]);
        $second = $handler->invoke(['path' => '/again'], null);

        self::assertSame(202, $first['statusCode']);
        self::assertSame(['Content-Type' => 'application/json', 'X-Multi' => ['a', 'b']], $first['headers'] ?? null);
        self::assertFalse($first['isBase64Encoded'] ?? null);
        self::assertArrayNotHasKey('multiValueHeaders', $first, 'Keys Scaleway does not document must be dropped.');
        self::assertSame(['calls' => 1, 'event' => $event, 'context' => ['memoryLimitInMb' => 128]], $this->decode($first));
        self::assertSame(['calls' => 2, 'event' => ['path' => '/again'], 'context' => null], $this->decode($second));
        self::assertSame(1, RecordingKernel::$builds);
    }

    public function testRawCallableMayOmitHeadersAsInScalewaysMinimalExample(): void
    {
        $response = $this->handler(['front_controller' => 'front-controllers/raw.php'])->invoke(['path' => '/minimal'], []);

        self::assertSame(['statusCode' => 200, 'body' => 'no headers'], $response);
    }

    public function testRawCallableGetsCommandInvocationsAsPlainEvents(): void
    {
        $event = $this->commandEvent('app:greet');

        $response = $this->handler(['front_controller' => 'front-controllers/raw.php'])->invoke($event, []);

        self::assertSame($event, $this->decode($response)['event']);
    }

    public function testFailedRawCallableBuildIsRetriedOnNextInvocation(): void
    {
        RecordingKernel::$failingBuilds = 1;
        $handler = $this->handler(['front_controller' => 'front-controllers/raw.php', 'debug' => false]);

        self::assertSame(500, $handler->invoke([], [])['statusCode']);
        self::assertStringContainsString('Build failed on purpose.', $this->errorLog());
        self::assertSame(202, $handler->invoke([], [])['statusCode']);
        self::assertSame(2, RecordingKernel::$builds);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failingRawCallables(): iterable
    {
        yield 'exception' => ['/throw', 'LogicException: Escaped on purpose.'];
        yield 'non-int status' => ['/invalid', 'must return a Function Response'];
        yield 'nested header value' => ['/bad-header', 'must return a Function Response'];
        yield 'plain string' => ['/string', '"string" returned'];
        yield 'null base64 flag' => ['/null-base64', 'must return a Function Response'];
    }

    #[DataProvider('failingRawCallables')]
    public function testFailingRawCallableIsA500AndTheProcessKeepsServing(string $path, string $message): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/raw.php', 'debug' => false]);

        self::assertSame([
            'statusCode' => 500,
            'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
            'body' => 'Internal Server Error',
        ], $handler->invoke(['path' => $path], []));
        self::assertStringContainsString($message, $this->errorLog());
        self::assertSame(['calls' => 2], array_intersect_key($this->decode($handler->invoke([], [])), ['calls' => 0]));
    }

    public function testFrontControllerMustReturnACallable(): void
    {
        $this->handler(['front_controller' => 'front-controllers/not-callable.php', 'debug' => false])->invoke([], []);

        self::assertStringContainsString('callable expected, "string" returned', $this->errorLog());
    }

    public function testOptionsComeFromComposerExtraRuntime(): void
    {
        $handler = $this->handler([], self::FIXTURES.'/ComposerProject');

        self::assertSame('handled #1 /', $handler->invoke([], [])['body']);
    }

    public function testRuntimeOptionsEnvVarWinsOverComposerExtraRuntime(): void
    {
        $handler = $this->handler(['front_controller' => 'front-controllers/unsupported.php', 'debug' => false], self::FIXTURES.'/ComposerProject');

        self::assertSame(500, $handler->invoke([], [])['statusCode']);
        self::assertStringContainsString('stdClass', $this->errorLog());
    }

    public function testRuntimeOptionsEnvVarIsDecodedFromJson(): void
    {
        $_SERVER['APP_RUNTIME_OPTIONS'] = json_encode([
            'error_handler' => false,
            'project_dir' => self::FIXTURES,
            'front_controller' => 'front-controllers/recording.php',
        ]);

        self::assertSame(200, new Handler(self::FIXTURES, $this->errorLog)->invoke([], [])['statusCode']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handler(array $options, string $composerDir = self::FIXTURES): Handler
    {
        // The Symfony error handler would take over PHPUnit's.
        $_SERVER['APP_RUNTIME_OPTIONS'] = $options + ['error_handler' => false];

        return new Handler($composerDir, $this->errorLog);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<mixed>
     */
    private function commandEvent(string $command, array $args = []): array
    {
        return [
            'method' => 'POST',
            'path' => '/',
            'body' => json_encode(['command' => $command, 'args' => (object) $args, 'secret' => 's3cret'], \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<mixed> $response
     *
     * @return array<mixed>
     */
    private function decode(array $response): array
    {
        self::assertIsString($response['body']);
        $data = json_decode($response['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function errorLog(): string
    {
        rewind($this->errorLog);

        return (string) stream_get_contents($this->errorLog);
    }
}
