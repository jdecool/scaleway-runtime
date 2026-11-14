<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Runner;

use JDecool\ScalewayRuntime\Runner\HttpKernelRunner;
use JDecool\ScalewayRuntime\Tests\Fixtures\RecordingKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class HttpKernelRunnerTest extends TestCase
{
    /** @var resource */
    private $errorLog;

    protected function setUp(): void
    {
        $errorLog = fopen('php://memory', 'w+');
        self::assertNotFalse($errorLog);
        $this->errorLog = $errorLog;
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    public function testNoProxyIsTrusted(): void
    {
        new HttpKernelRunner(new RecordingKernel(), $this->errorLog);

        self::assertSame([], Request::getTrustedProxies());
    }

    public function testRequestsGetTheHostOfTheFunction(): void
    {
        $kernel = new RecordingKernel();

        new HttpKernelRunner($kernel, $this->errorLog, 'app.example.com')->invoke(['path' => '/'], []);

        self::assertSame('app.example.com', $kernel->lastRequest?->getHost());
    }

    public function testTrustedProxiesConfiguredByTheAppAreKept(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        new HttpKernelRunner(new RecordingKernel(), $this->errorLog);

        self::assertSame(['10.0.0.0/8'], Request::getTrustedProxies());
        self::assertSame(Request::HEADER_X_FORWARDED_FOR, Request::getTrustedHeaderSet());
    }

    public function testKernelIsTerminatedAfterEachInvocation(): void
    {
        $kernel = new RecordingKernel();
        $runner = new HttpKernelRunner($kernel, $this->errorLog);

        $runner->invoke(['path' => '/'], []);
        $functionResponse = $runner->invoke(['path' => '/'], []);

        self::assertSame(['statusCode' => 200, 'body' => 'handled #2 /'], array_intersect_key($functionResponse, ['statusCode' => 0, 'body' => 0]));
        self::assertSame(2, $kernel->terminated);
    }

    public function testTerminationFailureKeepsTheResponseAndIsLogged(): void
    {
        $functionResponse = new HttpKernelRunner(new RecordingKernel(), $this->errorLog)->invoke(['path' => '/terminate-fails'], []);

        self::assertSame(200, $functionResponse['statusCode']);
        self::assertSame('handled #1 /terminate-fails', $functionResponse['body']);
        rewind($this->errorLog);
        self::assertStringContainsString('Termination failed on purpose.', (string) stream_get_contents($this->errorLog));
    }

    public function testUploadedFilesAreDeletedAfterTheInvocation(): void
    {
        $kernel = new RecordingKernel();

        new HttpKernelRunner($kernel, $this->errorLog)->invoke([
            'path' => '/',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=b'],
            'body' => "--b\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\n\r\nhello\r\n--b--\r\n",
        ], []);

        $file = $kernel->lastRequest?->files->get('doc');
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertFileDoesNotExist($file->getPathname());
    }
}
