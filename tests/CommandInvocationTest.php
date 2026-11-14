<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\CommandInvocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandInvocationTest extends TestCase
{
    /** @var array<mixed> */
    private array $server;
    /** @var resource */
    private $errorLog;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER[CommandInvocation::SECRET_VAR] = 's3cret';
        $errorLog = fopen('php://memory', 'w+');
        self::assertNotFalse($errorLog);
        $this->errorLog = $errorLog;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testAuthenticatedBodyNamingACommandIsACommandInvocation(): void
    {
        $invocation = $this->fromBody(['command' => 'app:sync', 'args' => ['source' => 'a', '--force' => true], 'secret' => 's3cret']);

        self::assertNotNull($invocation);
        self::assertSame('app:sync', $invocation->command);
        self::assertSame(['source' => 'a', '--force' => true], $invocation->args);
    }

    public function testArgsDefaultToNone(): void
    {
        self::assertSame([], $this->fromBody(['command' => 'app:sync', 'secret' => 's3cret'])?->args);
        self::assertSame([], $this->fromBody(['command' => 'app:sync', 'args' => [], 'secret' => 's3cret'])?->args);
    }

    public function testBase64BodyIsDecoded(): void
    {
        $event = ['body' => base64_encode('{"command":"app:sync","secret":"s3cret"}'), 'isBase64Encoded' => true];

        self::assertSame('app:sync', CommandInvocation::fromEvent($event, $this->errorLog)?->command);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function plainHttpBodies(): iterable
    {
        yield 'no secret' => [['command' => 'app:sync']];
        yield 'wrong secret' => [['command' => 'app:sync', 'secret' => 'guess']];
        yield 'secret of another type' => [['command' => 'app:sync', 'secret' => true]];
        yield 'no command' => [['args' => [], 'secret' => 's3cret']];
        yield 'command of another type' => [['command' => ['app:sync'], 'secret' => 's3cret']];
        yield 'not an object' => [['app:sync', 's3cret']];
    }

    #[DataProvider('plainHttpBodies')]
    public function testOtherBodiesAreNotCommandInvocations(mixed $body): void
    {
        self::assertNull($this->fromBody($body));
    }

    public function testBodyThatIsNotJsonIsNotACommandInvocation(): void
    {
        self::assertNull(CommandInvocation::fromEvent(['body' => 'command=app:sync&secret=s3cret'], $this->errorLog));
    }

    public function testNothingRunsWithoutAConfiguredSecret(): void
    {
        $_SERVER[CommandInvocation::SECRET_VAR] = '';

        self::assertNull($this->fromBody(['command' => 'app:sync', 'secret' => '']));
        self::assertStringContainsString('Command Invocation rejected', $this->errorLog());
    }

    public function testRejectionIsLoggedOnlyForBodiesCarryingASecret(): void
    {
        $this->fromBody(['command' => 'app:sync']);
        self::assertSame('', $this->errorLog());

        $this->fromBody(['command' => 'app:sync', 'secret' => 'guess']);
        self::assertStringContainsString('Command Invocation rejected', $this->errorLog());
        self::assertStringNotContainsString('guess', $this->errorLog());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function httpOnlyHeaders(): iterable
    {
        yield 'X-Real-Ip' => ['X-Real-Ip'];
        yield 'X-Envoy-External-Address' => ['X-Envoy-External-Address'];
        yield 'lowercase' => ['x-real-ip'];
    }

    #[DataProvider('httpOnlyHeaders')]
    public function testHttpCallCarryingTheSecretIsNotACommandInvocation(string $header): void
    {
        $event = ['httpMethod' => 'POST', 'path' => '/', 'headers' => [$header => '2001:db8::1'], 'body' => '{"command":"app:sync","secret":"s3cret"}'];

        self::assertNull(CommandInvocation::fromEvent($event, $this->errorLog));
        self::assertStringContainsString('the Event comes from an HTTP call', $this->errorLog());
        self::assertStringNotContainsString('s3cret', $this->errorLog());
    }

    public function testRealCronEventIsACommandInvocation(): void
    {
        $event = [
            'path' => '/',
            'httpMethod' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Forwarded' => 'for=100.96.2.180;proto=http',
                'K-Proxy-Request' => 'activator',
                'User-Agent' => 'Go-http-client/1.1',
                'X-Forwarded-For' => '100.96.2.180, 100.96.36.219',
                'X-Forwarded-Proto' => 'http',
                'X-Request-Id' => '0b8e4a52-7c1d-4e0f-8a3b-5d6c7e8f9a01',
            ],
            'queryStringParameters' => [],
            'body' => '{"command":"app:sync","args":{"--force":true},"secret":"s3cret"}',
        ];

        self::assertSame(['--force' => true], CommandInvocation::fromEvent($event, $this->errorLog)?->args);
    }

    public function testArgsMustNameTheirParameters(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->fromBody(['command' => 'app:sync', 'args' => ['a', '--force'], 'secret' => 's3cret']);
    }

    private function fromBody(mixed $body): ?CommandInvocation
    {
        return CommandInvocation::fromEvent(['method' => 'POST', 'path' => '/', 'body' => json_encode($body, \JSON_THROW_ON_ERROR)], $this->errorLog);
    }

    private function errorLog(): string
    {
        rewind($this->errorLog);

        return (string) stream_get_contents($this->errorLog);
    }
}
