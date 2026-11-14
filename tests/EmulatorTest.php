<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\Emulator;
use JDecool\ScalewayRuntime\HttpConverter;
use PHPUnit\Framework\TestCase;

final class EmulatorTest extends TestCase
{
    public function testTextRequestBecomesAPlainEvent(): void
    {
        $event = Emulator::toEvent('post', '/users/42?sort=name&filter%5Brole%5D=admin+user&flag', [
            'content-type' => 'application/json',
            'X-Forwarded-For' => '203.0.113.7',
        ], '{"name":"Zoé"}', '127.0.0.1');

        self::assertSame([
            'path' => '/users/42',
            'queryStringParameters' => ['sort' => 'name', 'filter[role]' => 'admin user', 'flag' => ''],
            'body' => '{"name":"Zoé"}',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Forwarded-For' => '203.0.113.7, 127.0.0.1',
                'X-Forwarded-Proto' => 'http',
            ],
            'method' => 'POST',
            'isBase64Encoded' => false,
        ], $event);
    }

    public function testBinaryBodyIsBase64Encoded(): void
    {
        $event = Emulator::toEvent('PUT', '/', [], "\xFF\x00\xFE", '127.0.0.1');

        self::assertTrue($event['isBase64Encoded']);
        self::assertSame("\xFF\x00\xFE", base64_decode($event['body'], true));
    }

    public function testRepeatedQueryKeyKeepsTheLastValue(): void
    {
        self::assertSame(['a' => '2'], Emulator::toEvent('GET', '/?a=1&a=2', [], '', '::1')['queryStringParameters']);
    }

    public function testEventRoundTripsThroughTheConverter(): void
    {
        $request = HttpConverter::toRequest(Emulator::toEvent('GET', '/search?q=caf%C3%A9&page%5Bsize%5D=10', ['Host' => 'example.test'], '', '192.0.2.1'));

        self::assertSame('/search', $request->getPathInfo());
        self::assertSame(['q' => 'café', 'page' => ['size' => '10']], $request->query->all());
    }
}
