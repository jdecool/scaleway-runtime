<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\CommandInvocation;
use JDecool\ScalewayRuntime\Emulator;
use JDecool\ScalewayRuntime\HttpConverter;
use PHPUnit\Framework\TestCase;

final class EmulatorTest extends TestCase
{
    public function testTextRequestBecomesAnEventShapedLikeThePlatformOnes(): void
    {
        $event = Emulator::toEvent('post', '/users/42?sort=name&filter%5Brole%5D=admin+user&flag', [
            'content-type' => 'application/json',
            'Host' => '127.0.0.1:8000',
            'x-custom' => 'hello',
        ], '{"name":"Zoé"}', '2001:db8::1');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event['headers']['X-Request-Id']);
        unset($event['headers']['X-Request-Id']);
        self::assertSame([
            'resource' => '/users/42',
            'path' => '/users/42',
            'httpMethod' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Forwarded' => 'for="[2001:db8::1]";proto=https',
                'K-Proxy-Request' => 'activator',
                'X-Custom' => 'hello',
                'X-Envoy-External-Address' => '2001:db8::1',
                'X-Forwarded-For' => '2001:db8::1',
                'X-Forwarded-Proto' => 'https',
                'X-Real-Ip' => '2001:db8::1',
            ],
            'multiValueHeaders' => null,
            'queryStringParameters' => ['sort' => 'name', 'filter[role]' => 'admin user', 'flag' => ''],
            'multiValueQueryStringParameters' => null,
            'pathParameters' => null,
            'stageVariables' => [],
            'requestContext' => ['httpMethod' => 'POST'],
            'body' => '{"name":"Zoé"}',
        ], $event);
    }

    public function testClientHeadersAreOverwrittenOrKeptLikeThePlatformDoes(): void
    {
        $headers = Emulator::toEvent('GET', '/', [
            'X-Forwarded-For' => '203.0.113.66',
            'X-Forwarded-Proto' => 'http',
            'X-Forwarded-Host' => 'spoofed.example',
            'X-Real-Ip' => '203.0.113.66',
            'X-Envoy-External-Address' => '203.0.113.66',
            'X-Request-Id' => 'forged',
            'Forwarded' => 'for=203.0.113.66',
        ], '', '192.0.2.1')['headers'];

        self::assertSame('203.0.113.66,192.0.2.1', $headers['X-Forwarded-For']);
        self::assertSame('https', $headers['X-Forwarded-Proto']);
        self::assertSame('spoofed.example', $headers['X-Forwarded-Host']);
        self::assertSame('192.0.2.1', $headers['X-Real-Ip']);
        self::assertSame('192.0.2.1', $headers['X-Envoy-External-Address']);
        self::assertNotSame('forged', $headers['X-Request-Id']);
        self::assertSame('for=203.0.113.66', $headers['Forwarded']);
    }

    public function testEmptyBinaryAndMultipartBodiesAreBase64Encoded(): void
    {
        $empty = Emulator::toEvent('GET', '/', [], '', '::1');
        self::assertSame(['', true], [$empty['body'], $empty['isBase64Encoded'] ?? false]);

        $binary = Emulator::toEvent('PUT', '/', [], "\xFF\x00\xFE", '::1');
        self::assertTrue($binary['isBase64Encoded'] ?? false);
        self::assertSame("\xFF\x00\xFE", base64_decode($binary['body'], true));

        $multipart = Emulator::toEvent('POST', '/', ['Content-Type' => 'multipart/form-data; boundary=b'], "--b--\r\n", '::1');
        self::assertSame([base64_encode("--b--\r\n"), true], [$multipart['body'], $multipart['isBase64Encoded'] ?? false]);
    }

    public function testQueryStringParametersKeepTheLastValueAndAreAnEmptyListWithoutQueryString(): void
    {
        self::assertSame(['a' => '2', 'list[]' => 'y'], Emulator::toEvent('GET', '/?a=1&a=2&list[]=x&list[]=y', [], '', '::1')['queryStringParameters']);
        self::assertSame('[]', json_encode(Emulator::toEvent('GET', '/', [], '', '::1')['queryStringParameters']));
    }

    public function testEventRoundTripsThroughTheConverter(): void
    {
        $request = HttpConverter::toRequest(Emulator::toEvent('GET', '/search?q=caf%C3%A9&page%5Bsize%5D=10', [], '', '192.0.2.1'));

        self::assertSame('/search', $request->getPathInfo());
        self::assertSame(['q' => 'café', 'page' => ['size' => '10']], $request->query->all());
        self::assertSame('192.0.2.1', $request->getClientIp());
        self::assertTrue($request->isSecure());
    }

    public function testCronEventIsACommandInvocationAndHttpEventsAreNot(): void
    {
        $server = $_SERVER;
        $_SERVER[CommandInvocation::SECRET_VAR] = 's3cret';
        $errorLog = fopen('php://memory', 'w+');
        self::assertNotFalse($errorLog);
        $args = '{"command":"app:sync","secret":"s3cret"}';
        try {
            $cron = Emulator::toCronEvent($args);
            self::assertSame('app:sync', CommandInvocation::fromEvent($cron, $errorLog)?->command);
            self::assertNull(CommandInvocation::fromEvent(Emulator::toEvent('POST', '/', ['Content-Type' => 'application/json'], $args, '::1'), $errorLog));
        } finally {
            $_SERVER = $server;
        }

        self::assertSame($args, $cron['body']);
        self::assertArrayNotHasKey('X-Real-Ip', $cron['headers']);
    }
}
