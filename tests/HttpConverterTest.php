<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\HttpConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HttpConverterTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    public function testRequestFromFullEvent(): void
    {
        $request = HttpConverter::toRequest([
            'path' => '/blog/post',
            'method' => 'put',
            'queryStringParameters' => ['page' => '2', 'q' => 'a b&c'],
            'headers' => ['Content-Type' => 'application/json', 'Content-Length' => '13', 'X-Custom-Header' => 'yes', 'Host' => 'example.com'],
            'body' => '{"ok": true}',
            'isBase64Encoded' => false,
        ]);

        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/blog/post', $request->getPathInfo());
        self::assertSame(['page' => '2', 'q' => 'a b&c'], $request->query->all());
        self::assertSame('page=2&q=a+b%26c', $request->server->get('QUERY_STRING'));
        self::assertSame('/blog/post?page=2&q=a+b%26c', $request->getRequestUri());
        self::assertSame('application/json', $request->headers->get('Content-Type'));
        self::assertSame('13', $request->headers->get('Content-Length'));
        self::assertSame('yes', $request->headers->get('X-Custom-Header'));
        self::assertSame('http://example.com/blog/post?page=2&q=a%20b%26c', $request->getUri());
        self::assertSame('{"ok": true}', $request->getContent());
        self::assertSame(['ok' => true], $request->toArray());
    }

    public function testRequestFromEmptyEvent(): void
    {
        $request = HttpConverter::toRequest([]);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getPathInfo());
        self::assertSame('/', $request->getRequestUri());
        self::assertSame([], $request->query->all());
        self::assertSame('', $request->getContent());
        self::assertSame(HttpConverter::PROXY_ADDRESS, $request->getClientIp());
    }

    public function testHttpMethodFieldIsAccepted(): void
    {
        self::assertSame('DELETE', HttpConverter::toRequest(['httpMethod' => 'DELETE'])->getMethod());
    }

    public function testPathWithoutLeadingSlashIsNormalized(): void
    {
        self::assertSame('/health', HttpConverter::toRequest(['path' => 'health'])->getPathInfo());
    }

    public function testArrayQueryParametersAreParsedLikePhp(): void
    {
        $request = HttpConverter::toRequest(['queryStringParameters' => ['filter[status]' => 'open', 'tags[]' => 'php']]);

        self::assertSame(['filter' => ['status' => 'open'], 'tags' => ['php']], $request->query->all());
    }

    public function testQueryStringIgnoresTheConfiguredArgSeparator(): void
    {
        $separator = ini_set('arg_separator.output', '&amp;');
        try {
            $request = HttpConverter::toRequest(['queryStringParameters' => ['a' => '1', 'b' => '2']]);
        } finally {
            ini_set('arg_separator.output', (string) $separator);
        }

        self::assertSame(['a' => '1', 'b' => '2'], $request->query->all());
    }

    public function testBase64BodyIsDecoded(): void
    {
        $binary = random_bytes(64)."\xff\xfe";

        $request = HttpConverter::toRequest(['method' => 'POST', 'body' => base64_encode($binary), 'isBase64Encoded' => true]);

        self::assertSame($binary, $request->getContent());
    }

    public function testInvalidBase64BodyIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        HttpConverter::toRequest(['body' => '%%%', 'isBase64Encoded' => true]);
    }

    public function testFormBodyIsParsed(): void
    {
        $request = HttpConverter::toRequest([
            'method' => 'PATCH',
            'headers' => ['content-type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
            'body' => 'name=Jane+Doe&roles[]=admin',
        ]);

        self::assertSame(['name' => 'Jane Doe', 'roles' => ['admin']], $request->request->all());
    }

    public function testFormBodyIsIgnoredForGet(): void
    {
        $request = HttpConverter::toRequest([
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'name=Jane',
        ]);

        self::assertSame([], $request->request->all());
    }

    public function testCookiesAreParsedLikePhp(): void
    {
        $request = HttpConverter::toRequest(['headers' => ['Cookie' => 'session=abc%3D%3D; theme=dark;session=ignored; broken; =nameless']]);

        self::assertSame(['session' => 'abc==', 'theme' => 'dark'], $request->cookies->all());
    }

    public function testMalformedEventFieldsAreIgnored(): void
    {
        $request = HttpConverter::toRequest([
            'path' => ['not', 'a', 'string'],
            'headers' => 'not a map',
            'queryStringParameters' => ['ok' => '1', 'nested' => ['x' => ['y']]],
            'body' => null,
        ]);

        self::assertSame('/', $request->getPathInfo());
        self::assertSame(['ok' => '1', 'nested' => ''], $request->query->all());
        self::assertSame('', $request->getContent());
    }

    public function testListHeaderValuesAreFolded(): void
    {
        $request = HttpConverter::toRequest(['headers' => ['Accept' => ['text/html', 'application/json']]]);

        self::assertSame('text/html, application/json', $request->headers->get('Accept'));
    }

    public function testForwardedHeadersApplyOnceTheProxyIsTrusted(): void
    {
        Request::setTrustedProxies([HttpConverter::PROXY_ADDRESS], HttpConverter::TRUSTED_HEADERS);

        $request = HttpConverter::toRequest([
            'path' => '/',
            'headers' => [
                'Host' => 'internal.local',
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Host' => 'app.example.com',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
            ],
        ]);

        self::assertTrue($request->isSecure());
        self::assertSame('app.example.com', $request->getHost());
        self::assertSame('https://app.example.com/', $request->getUri());
        self::assertSame('203.0.113.7', $request->getClientIp());
    }

    public function testForwardedHeadersAreIgnoredWhileTheProxyIsUntrusted(): void
    {
        $request = HttpConverter::toRequest(['headers' => ['Host' => 'internal.local', 'X-Forwarded-Proto' => 'https']]);

        self::assertFalse($request->isSecure());
        self::assertSame('internal.local', $request->getHost());
    }

    public function testFunctionResponseFromTextResponse(): void
    {
        $response = new Response('{"a":"é"}', 201, ['Content-Type' => 'application/json']);

        $functionResponse = HttpConverter::toFunctionResponse($response);

        self::assertSame(201, $functionResponse['statusCode']);
        self::assertSame('{"a":"é"}', $functionResponse['body']);
        self::assertSame('application/json', $functionResponse['headers']['Content-Type']);
        self::assertArrayNotHasKey('isBase64Encoded', $functionResponse);
    }

    public function testEmptyBody(): void
    {
        $functionResponse = HttpConverter::toFunctionResponse(new Response('', 204));

        self::assertSame(204, $functionResponse['statusCode']);
        self::assertSame('', $functionResponse['body']);
        self::assertArrayNotHasKey('isBase64Encoded', $functionResponse);
    }

    public function testBinaryBodyIsBase64Encoded(): void
    {
        $binary = "\x89PNG\r\n\x1a\n\x00\xff";

        $functionResponse = HttpConverter::toFunctionResponse(new Response($binary));

        self::assertSame(base64_encode($binary), $functionResponse['body']);
        self::assertTrue($functionResponse['isBase64Encoded'] ?? null);
    }

    public function testMultiValueHeadersAndCookiesAreLists(): void
    {
        $response = new Response();
        $response->headers->set('Vary', ['Accept', 'Accept-Language']);
        $response->headers->setCookie(Cookie::create('a', '1'));
        $response->headers->setCookie(Cookie::create('b', 'x y', path: '/admin'));

        $headers = HttpConverter::toFunctionResponse($response)['headers'];

        self::assertSame(['Accept', 'Accept-Language'], $headers['Vary']);
        self::assertSame(['a=1; path=/; httponly; samesite=lax', 'b=x%20y; path=/admin; httponly; samesite=lax'], $headers['Set-Cookie']);
    }

    public function testSingleCookieIsAString(): void
    {
        $response = new Response();
        $response->headers->setCookie(Cookie::create('a', '1'));

        self::assertSame('a=1; path=/; httponly; samesite=lax', HttpConverter::toFunctionResponse($response)['headers']['Set-Cookie']);
    }

    public function testNoSetCookieHeaderWithoutCookies(): void
    {
        self::assertArrayNotHasKey('Set-Cookie', HttpConverter::toFunctionResponse(new Response())['headers']);
    }

    public function testStreamedResponseIsBuffered(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'chunk 1, ';
            // Streamed callbacks commonly flush: the chunk must still reach the Function Response.
            ob_flush();
            flush();
            echo 'chunk 2';
        });

        self::assertSame('chunk 1, chunk 2', HttpConverter::toFunctionResponse($response)['body']);
    }

    public function testOutputBuffersLeftOpenByTheResponseAreClosed(): void
    {
        $level = ob_get_level();

        $body = HttpConverter::toFunctionResponse(new StreamedResponse(static function (): void {
            ob_start();
            echo 'kept';
        }))['body'];

        self::assertSame($level, ob_get_level());
        self::assertSame('kept', $body);
    }

    public function testBinaryFileResponseIsBuffered(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'scaleway-runtime');
        self::assertNotFalse($file);
        file_put_contents($file, "\xff\xd8binary");

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend();

        $functionResponse = HttpConverter::toFunctionResponse($response);

        self::assertSame(base64_encode("\xff\xd8binary"), $functionResponse['body']);
        self::assertTrue($functionResponse['isBase64Encoded'] ?? null);
        self::assertFileDoesNotExist($file);
    }
}
