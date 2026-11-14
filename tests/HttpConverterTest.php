<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\HttpConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HttpConverterTest extends TestCase
{
    /** An HTTP Event as measured on Scaleway (docs/probe-results.md), GET without a body. */
    private const array PLATFORM_EVENT = [
        'resource' => '/some/path',
        'path' => '/some/path',
        'httpMethod' => 'GET',
        'headers' => [
            'Accept' => '*/*',
            'Forwarded' => 'for="[2001:db8::1]";proto=https, for=100.96.1.187',
            'K-Proxy-Request' => 'activator',
            'User-Agent' => 'curl/8.7.1',
            'X-Custom' => 'hello',
            'X-Envoy-External-Address' => '2001:db8::1',
            'X-Forwarded-For' => '2001:db8::1, 100.96.1.187, 100.96.38.33',
            'X-Forwarded-Proto' => 'https',
            'X-Real-Ip' => '2001:db8::1',
            'X-Request-Id' => '6f1c1e0a-3b4e-4f7a-9a52-0d1f3e6c2b11',
        ],
        'multiValueHeaders' => null,
        'queryStringParameters' => ['single' => '1', 'repeated' => 'b', 'list[]' => 'y'],
        'multiValueQueryStringParameters' => null,
        'pathParameters' => null,
        'stageVariables' => [],
        'requestContext' => ['httpMethod' => 'GET'],
        'body' => '',
        'isBase64Encoded' => true,
    ];

    /** A CRON Event as measured on Scaleway (docs/probe-results.md). */
    private const array CRON_EVENT = [
        'path' => '/',
        'httpMethod' => 'POST',
        'headers' => [
            'Content-Length' => '40',
            'Content-Type' => 'application/json',
            'Forwarded' => 'for=100.96.2.180;proto=http',
            'K-Proxy-Request' => 'activator',
            'User-Agent' => 'Go-http-client/1.1',
            'X-Forwarded-For' => '100.96.2.180, 100.96.36.219',
            'X-Forwarded-Proto' => 'http',
            'X-Request-Id' => '0b8e4a52-7c1d-4e0f-8a3b-5d6c7e8f9a01',
        ],
        'queryStringParameters' => [],
        'body' => '{"probe":"cron","nested":{"list":[1,2]}}',
    ];

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
            'headers' => ['Content-Type' => 'application/json', 'Content-Length' => '13', 'X-Custom-Header' => 'yes'],
            'body' => '{"ok": true}',
            'isBase64Encoded' => false,
        ], 'example.com');

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

    public function testMultipartBodyIsParsedLikePhp(): void
    {
        $body = implode("\r\n", [
            '--XyZ',
            'Content-Disposition: form-data; name="title"',
            '',
            'Hello & welcome',
            '--XyZ',
            'Content-Disposition: form-data; name="tags[]"',
            '',
            'php',
            '--XyZ',
            'Content-Disposition: form-data; name="doc[cv]"; filename="C:\\Users\\me\\résumé.pdf"',
            'Content-Type: application/pdf',
            '',
            "%PDF\x00\xff",
            '--XyZ',
            'Content-Disposition: form-data; name="photos[]"; filename="a.png"',
            'Content-Type: image/png',
            '',
            '',
            '--XyZ',
            'Content-Disposition: form-data; name="avatar"; filename=""',
            'Content-Type: application/octet-stream',
            '',
            '',
            '--XyZ--',
            '',
        ]);

        $request = HttpConverter::toRequest([
            'method' => 'POST',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=XyZ'],
            'body' => base64_encode($body),
            'isBase64Encoded' => true,
        ]);

        self::assertSame(['title' => 'Hello & welcome', 'tags' => ['php']], $request->request->all());
        self::assertNull($request->files->get('avatar'));

        $docs = $request->files->get('doc');
        self::assertIsArray($docs);
        $doc = $docs['cv'];
        self::assertInstanceOf(UploadedFile::class, $doc);
        self::assertTrue($doc->isValid());
        self::assertSame('résumé.pdf', $doc->getClientOriginalName());
        self::assertSame('application/pdf', $doc->getClientMimeType());
        self::assertSame("%PDF\x00\xff", file_get_contents($doc->getPathname()));

        $photos = $request->files->get('photos');
        self::assertIsArray($photos);
        $photo = $photos[0];
        self::assertInstanceOf(UploadedFile::class, $photo);
        self::assertSame(0, $photo->getSize());

        // The file can be moved like a real upload.
        $target = sys_get_temp_dir().'/scaleway-runtime-'.bin2hex(random_bytes(4));
        $moved = $doc->move($target);
        self::assertSame("%PDF\x00\xff", file_get_contents($moved->getPathname()));
        unlink($moved->getPathname());
        rmdir($target);
        unlink($photo->getPathname());
    }

    public function testMalformedMultipartBodyIsIgnoredLikePhp(): void
    {
        $request = HttpConverter::toRequest([
            'method' => 'POST',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=XyZ'],
            'body' => 'not multipart at all',
        ]);

        self::assertSame([], $request->request->all());
        self::assertSame([], $request->files->all());
        self::assertSame('not multipart at all', $request->getContent());
    }

    public function testMultipartBodyIsOnlyParsedForPostLikePhp(): void
    {
        $request = HttpConverter::toRequest([
            'method' => 'PUT',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=b'],
            'body' => "--b\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n--b--\r\n",
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

    public function testClientAndSchemeComeFromTheHeadersThePlatformOverwrites(): void
    {
        $request = HttpConverter::toRequest(self::PLATFORM_EVENT);

        self::assertSame('2001:db8::1', $request->getClientIp());
        self::assertTrue($request->isSecure());
        self::assertSame(443, $request->getPort());
        self::assertSame('https://localhost/some/path?list%5B0%5D=y&repeated=b&single=1', $request->getUri());
    }

    public function testClientForgeableForwardingHeadersAreIgnored(): void
    {
        $request = HttpConverter::toRequest(['headers' => [
            'X-Real-Ip' => '2001:db8::1',
            'X-Forwarded-For' => '203.0.113.66,2001:db8::1, 100.96.2.180',
            'X-Forwarded-Host' => 'spoofed.example',
            'X-Forwarded-Port' => '1234',
            'Forwarded' => 'for=203.0.113.66;host=spoofed.example',
            'Host' => 'spoofed.example',
            'X-Forwarded-Proto' => 'http',
        ]], 'app.example.com');

        self::assertSame('2001:db8::1', $request->getClientIp());
        self::assertSame('http://app.example.com/', $request->getUri());
        self::assertSame(80, $request->getPort());
        self::assertSame([], Request::getTrustedProxies());
    }

    public function testHeadersWithUnderscoresCannotShadowThePlatformOnes(): void
    {
        $request = HttpConverter::toRequest(['headers' => [
            'X-Forwarded-Proto' => 'https',
            'X-Real-Ip' => '2001:db8::1',
            'X_forwarded_proto' => 'http',
            'X_real_ip' => '203.0.113.66',
        ]]);

        self::assertSame('2001:db8::1', $request->getClientIp());
        self::assertTrue($request->isSecure());
    }

    public function testTrustedProxiesConfiguredByTheAppStillApply(): void
    {
        Request::setTrustedProxies(['2001:db8::1'], Request::HEADER_X_FORWARDED_HOST);

        $request = HttpConverter::toRequest(['headers' => ['X-Real-Ip' => '2001:db8::1', 'X-Forwarded-Host' => 'app.example.com']]);

        self::assertSame('app.example.com', $request->getHost());
    }

    public function testCronEventComesFromThePlatformOverHttp(): void
    {
        $request = HttpConverter::toRequest(self::CRON_EVENT);

        self::assertSame('POST', $request->getMethod());
        self::assertSame(HttpConverter::PROXY_ADDRESS, $request->getClientIp());
        self::assertFalse($request->isSecure());
        self::assertSame('http://localhost/', $request->getUri());
        self::assertSame(['probe' => 'cron', 'nested' => ['list' => [1, 2]]], $request->toArray());
    }

    public function testMalformedRealIpFallsBackToTheProxyAddress(): void
    {
        self::assertSame(HttpConverter::PROXY_ADDRESS, HttpConverter::toRequest(['headers' => ['X-Real-Ip' => 'not an ip']])->getClientIp());
    }

    public function testEmptyHostOptionGetsTheDefaultHostOfRequestCreate(): void
    {
        $request = HttpConverter::toRequest(['path' => '/jobs', 'httpMethod' => 'POST'], '');

        self::assertSame('http://localhost/jobs', $request->getUri());
        self::assertSame(80, $request->getPort());
    }

    public function testRealEventShapes(): void
    {
        // Text bodies come without isBase64Encoded, and an Event without query string has an empty list.
        $text = HttpConverter::toRequest(['httpMethod' => 'POST', 'headers' => ['Content-Type' => 'application/json'], 'queryStringParameters' => [], 'body' => '{"a":"é"}']
            + array_diff_key(self::PLATFORM_EVENT, ['isBase64Encoded' => true]));
        self::assertSame('{"a":"é"}', $text->getContent());
        self::assertSame([], $text->query->all());

        $emptyGet = HttpConverter::toRequest(self::PLATFORM_EVENT);
        self::assertSame('', $emptyGet->getContent());
        self::assertSame(['single' => '1', 'repeated' => 'b', 'list' => ['y']], $emptyGet->query->all());
        self::assertSame('hello', $emptyGet->headers->get('X-Custom'));
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
