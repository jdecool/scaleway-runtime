<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Runner;

use JDecool\ScalewayRuntime\HttpConverter;
use JDecool\ScalewayRuntime\Runner\HttpKernelRunner;
use JDecool\ScalewayRuntime\Tests\Fixtures\RecordingKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HttpKernelRunnerTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    public function testPlatformProxyIsTrustedByDefault(): void
    {
        new HttpKernelRunner(new RecordingKernel());

        self::assertSame([HttpConverter::PROXY_ADDRESS], Request::getTrustedProxies());
        self::assertSame(HttpConverter::TRUSTED_HEADERS, Request::getTrustedHeaderSet());
    }

    public function testTrustedProxiesConfiguredByTheAppAreKept(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        new HttpKernelRunner(new RecordingKernel());

        self::assertSame(['10.0.0.0/8'], Request::getTrustedProxies());
        self::assertSame(Request::HEADER_X_FORWARDED_FOR, Request::getTrustedHeaderSet());
    }

    public function testKernelIsTerminatedAfterEachInvocation(): void
    {
        $kernel = new RecordingKernel();
        $runner = new HttpKernelRunner($kernel);

        $runner->invoke(['path' => '/'], []);
        $functionResponse = $runner->invoke(['path' => '/'], []);

        self::assertSame(['statusCode' => 200, 'body' => 'handled #2 /'], array_intersect_key($functionResponse, ['statusCode' => 0, 'body' => 0]));
        self::assertSame(2, $kernel->terminated);
    }
}
