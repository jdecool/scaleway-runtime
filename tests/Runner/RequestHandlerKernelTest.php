<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Runner;

use JDecool\ScalewayRuntime\Runner\HttpKernelRunner;
use JDecool\ScalewayRuntime\Runner\RequestHandlerKernel;
use JDecool\ScalewayRuntime\Tests\Fixtures\EchoRequestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\HttpFoundation\Request;

final class RequestHandlerKernelTest extends TestCase
{
    private EchoRequestHandler $handler;
    private HttpKernelRunner $runner;

    protected function setUp(): void
    {
        $errorLog = fopen('php://memory', 'w+');
        self::assertNotFalse($errorLog);
        $this->handler = new EchoRequestHandler();
        $this->runner = new HttpKernelRunner(new RequestHandlerKernel($this->handler), $errorLog);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    public function testRequestCarriesTheClientBehindThePlatformProxy(): void
    {
        $echo = $this->echo([
            'path' => '/items',
            'queryStringParameters' => ['page' => '2'],
            'headers' => [
                'Host' => 'internal.local',
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Host' => 'api.example.com',
                'X-Forwarded-Proto' => 'https',
            ],
        ]);

        self::assertSame('https://api.example.com/items?page=2', $echo['uri']);
        self::assertSame('203.0.113.7', $echo['remoteAddr']);
        self::assertSame(['page' => '2'], $echo['query']);
    }

    public function testRequestWithoutForwardedHeadersComesFromThePlatformProxy(): void
    {
        $echo = $this->echo(['path' => '/', 'headers' => ['Host' => 'internal.local']]);

        self::assertSame('http://internal.local/', $echo['uri']);
        self::assertSame('127.0.0.1', $echo['remoteAddr']);
    }

    public function testEventWithoutHostGetsAParsableUri(): void
    {
        self::assertSame('http://localhost/', $this->echo(['path' => '/'])['uri']);
        self::assertSame('https://localhost/', $this->echo(['path' => '/', 'headers' => ['X-Forwarded-Proto' => 'https']])['uri']);
    }

    public function testMalformedHostIsABadRequestThatSkipsTheHandler(): void
    {
        $functionResponse = $this->runner->invoke(['path' => '/', 'headers' => ['Host' => 'internal.local', 'X-Forwarded-Host' => 'bad host!']], []);

        self::assertSame(400, $functionResponse['statusCode']);
        self::assertSame(0, $this->handler->handled);
    }

    public function testBodiesAndUploadsReachTheHandler(): void
    {
        $echo = $this->echo([
            'path' => '/',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=b'],
            'body' => base64_encode("--b\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nReport\r\n"
                ."--b\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\n\r\nhello\r\n--b--\r\n"),
            'isBase64Encoded' => true,
        ]);

        self::assertSame(['title' => 'Report'], $echo['parsedBody']);
        self::assertSame(['doc' => ['name' => 'a.txt', 'content' => 'hello']], $echo['files']);
        $file = $this->handler->lastRequest?->getUploadedFiles()['doc'] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $file);
        $path = $file->getStream()->getMetadata('uri');
        self::assertIsString($path);
        self::assertFileDoesNotExist($path, 'Uploaded files must be deleted after the Invocation.');
    }

    public function testRawBodyReachesTheHandler(): void
    {
        $echo = $this->echo(['path' => '/', 'method' => 'POST', 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"a":1}']);

        self::assertSame('{"a":1}', $echo['body']);
        self::assertSame(['a' => 1], $echo['parsedBody']);
    }

    public function testResponseKeepsStatusMultiValueHeadersAndCookies(): void
    {
        $functionResponse = $this->runner->invoke(['path' => '/'], []);

        self::assertSame(201, $functionResponse['statusCode']);
        self::assertSame('application/json', $functionResponse['headers']['Content-Type'] ?? null);
        self::assertSame(['a', 'b'], $functionResponse['headers']['X-Multi'] ?? null);
        self::assertSame(['first=1; path=/', 'second=2; path=/'], $functionResponse['headers']['Set-Cookie'] ?? null);
    }

    public function testResettableHandlerIsResetBeforeEachInvocationButTheFirst(): void
    {
        self::assertSame(0, $this->echo(['path' => '/'])['resets']);
        self::assertSame(1, $this->echo(['path' => '/'])['resets']);
    }

    public function testHandlerIsResetAfterAFailedInvocation(): void
    {
        try {
            $this->runner->invoke(['path' => '/throw'], []);
            self::fail('The exception must escape to the Handler.');
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $this->echo(['path' => '/'])['resets']);
    }

    /**
     * @param array<mixed> $event
     *
     * @return array<mixed>
     */
    private function echo(array $event): array
    {
        $functionResponse = $this->runner->invoke($event, []);
        $echo = json_decode($functionResponse['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($echo);

        return $echo;
    }
}
