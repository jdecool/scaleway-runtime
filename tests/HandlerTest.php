<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\Handler;
use JDecool\ScalewayRuntime\Tests\Fixtures\RecordingKernel;
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

        $first = $handler->invoke(['queryStringParameters' => ['name' => 'Ada'], 'headers' => ['X-Forwarded-For' => '203.0.113.7']], []);
        $second = $handler->invoke(['queryStringParameters' => ['name' => 'Grace']], []);

        self::assertSame('Hello Ada from 203.0.113.7', $first['body']);
        self::assertSame('Hello Grace from 127.0.0.1', $second['body']);
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
