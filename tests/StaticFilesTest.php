<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\StaticFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class StaticFilesTest extends TestCase
{
    private string $root;
    /** @var resource */
    private $errorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/static-files-'.bin2hex(random_bytes(4));
        $errorLog = fopen('php://memory', 'w+');
        self::assertNotFalse($errorLog);
        $this->errorLog = $errorLog;
        $this->write('app.css', 'body { color: red; }');
        $this->write('build/app.js', 'console.log("app");');
        $this->write('.env', 'APP_SECRET=leaked');
        $this->write('index.php', '<?php echo "source";');
        $this->write('.well-known/security.txt', 'Contact: mailto:security@example.com');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testServesAFileWithItsTypeAndCacheHeaders(): void
    {
        $response = $this->serve('/app.css');

        self::assertSame(200, $response['statusCode']);
        self::assertSame('body { color: red; }', $response['body']);
        self::assertArrayNotHasKey('isBase64Encoded', $response);
        self::assertSame('text/css', $response['headers']['Content-Type'] ?? null);
        self::assertSame('nosniff', $response['headers']['X-Content-Type-Options'] ?? null);
        self::assertSame('public, max-age=0, must-revalidate', $response['headers']['Cache-Control'] ?? null);
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $response['headers']['ETag'] ?? '');
        self::assertArrayNotHasKey('Vary', $response['headers']);
    }

    public function testServesBinaryFilesBase64Encoded(): void
    {
        $png = "\x89PNG\r\n\x1a\n\x00\xff";
        $this->write('logo.png', $png);

        $response = $this->serve('/logo.png');

        self::assertSame('image/png', $response['headers']['Content-Type'] ?? null);
        self::assertTrue($response['isBase64Encoded'] ?? false);
        self::assertSame($png, base64_decode($response['body'], true));
    }

    public function testUnknownExtensionsAreOctetStreams(): void
    {
        $this->write('data.unknown-ext', 'x');

        self::assertSame('application/octet-stream', $this->serve('/data.unknown-ext')['headers']['Content-Type'] ?? null);
    }

    public function testDecodesThePath(): void
    {
        $this->write('with space.txt', 'spaced');

        self::assertSame('spaced', $this->serve('/with%20space.txt')['body']);
    }

    public function testHeadHasTheHeadersButNoBody(): void
    {
        $response = $this->serve('/app.css', 'HEAD');

        self::assertSame(200, $response['statusCode']);
        self::assertSame('', $response['body']);
        self::assertSame('text/css', $response['headers']['Content-Type'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invocationsForTheApplication(): iterable
    {
        yield 'POST' => ['/app.css', 'POST'];
        yield 'root' => ['/', 'GET'];
        yield 'directory' => ['/build', 'GET'];
        yield 'missing file' => ['/missing.css', 'GET'];
        yield 'PHP file' => ['/index.php', 'GET'];
        yield 'PHP file in upper case' => ['/INDEX.PHP', 'GET'];
        yield 'hidden file' => ['/.env', 'GET'];
        yield 'parent directory' => ['/build/../app.css', 'GET'];
        yield 'encoded parent directory' => ['/build/%2e%2e/app.css', 'GET'];
        yield 'escaping the web root' => ['/../composer.json', 'GET'];
        yield 'null byte' => ['/app.css%00.png', 'GET'];
        yield 'nested .well-known' => ['/build/.well-known/security.txt', 'GET'];
    }

    #[DataProvider('invocationsForTheApplication')]
    public function testLeavesOtherInvocationsToTheApplication(string $path, string $method): void
    {
        new Filesystem()->dumpFile($this->root.'/composer.json', '{}');

        self::assertNull($this->staticFiles()->serve(['path' => $path, 'httpMethod' => $method]));
    }

    public function testServesWellKnownFiles(): void
    {
        self::assertSame('Contact: mailto:security@example.com', $this->serve('/.well-known/security.txt')['body']);
    }

    public function testSymbolicLinksLeavingTheWebRootAreNotServed(): void
    {
        $outside = $this->root.'-outside';
        new Filesystem()->dumpFile($outside.'/secret.txt', 'secret');
        symlink($outside, $this->root.'/public/link');

        try {
            self::assertNull($this->staticFiles()->serve(['path' => '/link/secret.txt']));
        } finally {
            new Filesystem()->remove($outside);
        }
    }

    public function testMissingWebRootServesNothing(): void
    {
        self::assertNull(new StaticFiles($this->root.'/missing', null, $this->errorLog)->serve(['path' => '/app.css']));
    }

    public function testMatchingIfNoneMatchIsA304WithoutBody(): void
    {
        $etag = $this->serve('/app.css')['headers']['ETag'] ?? '';

        foreach ([$etag, 'W/'.$etag, '"other", '.$etag, '*'] as $ifNoneMatch) {
            $response = $this->serve('/app.css', headers: ['If-None-Match' => $ifNoneMatch]);
            self::assertSame(304, $response['statusCode'], $ifNoneMatch);
            self::assertSame('', $response['body']);
            self::assertSame($etag, $response['headers']['ETag'] ?? null);
        }
        self::assertSame(200, $this->serve('/app.css', headers: ['If-None-Match' => '"other"'])['statusCode']);
    }

    public function testETagFollowsAFileChangingWithinTheWarmProcess(): void
    {
        $staticFiles = $this->staticFiles();
        $before = $staticFiles->serve(['path' => '/app.css'])['headers']['ETag'] ?? null;
        $this->write('app.css', 'body { color: blue; }');
        touch($this->root.'/public/app.css', time() + 10);

        self::assertNotSame($before, $staticFiles->serve(['path' => '/app.css'])['headers']['ETag'] ?? null);
    }

    public function testFilesADigestingManifestMapsAreImmutable(): void
    {
        // As AssetMapper writes it, then as Webpack Encore does, with versioning off for one entry.
        $this->write('assets/manifest.json', json_encode(['app.css' => '/assets/app-mH7q4W_.css', 'styles/x.css' => 'https://cdn.example.com/assets/styles/x-Ab1Cd2E.css']) ?: '');
        $this->write('assets/app-mH7q4W_.css', 'a');
        $this->write('assets/styles/x-Ab1Cd2E.css', 'x');
        $this->write('build/manifest.json', json_encode(['build/app.js' => '/build/app.js', 'build/vendor.js' => '/build/vendor.3f2a1b9c.js']) ?: '');
        $this->write('build/vendor.3f2a1b9c.js', 'v');
        $this->write('font-awesome.css', 'f');

        $immutable = 'public, max-age=31536000, immutable';
        self::assertSame($immutable, $this->serve('/assets/app-mH7q4W_.css')['headers']['Cache-Control'] ?? null);
        self::assertSame($immutable, $this->serve('/assets/styles/x-Ab1Cd2E.css')['headers']['Cache-Control'] ?? null);
        self::assertSame($immutable, $this->serve('/build/vendor.3f2a1b9c.js')['headers']['Cache-Control'] ?? null);
        self::assertNotSame($immutable, $this->serve('/build/app.js')['headers']['Cache-Control'] ?? null);
        self::assertNotSame($immutable, $this->serve('/font-awesome.css')['headers']['Cache-Control'] ?? null);
        self::assertNotSame($immutable, $this->serve('/assets/manifest.json')['headers']['Cache-Control'] ?? null);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function acceptEncodings(): iterable
    {
        yield 'none' => ['', null];
        yield 'gzip only' => ['gzip', 'gzip'];
        yield 'both, brotli preferred whatever the weights' => ['gzip;q=1.0, br;q=0.1', 'br'];
        yield 'brotli refused' => ['gzip, br;q=0', 'gzip'];
        yield 'wildcard' => ['*', 'br'];
        yield 'wildcard but brotli' => ['*, br;q=0', 'gzip'];
        yield 'everything refused' => ['br;q=0, gzip;q=0', null];
        yield 'case insensitive' => ['GZIP', 'gzip'];
    }

    #[DataProvider('acceptEncodings')]
    public function testServesThePreferredPrecompressedVariant(string $acceptEncoding, ?string $expected): void
    {
        $this->write('app.css.br', 'brotli bytes');
        $this->write('app.css.gz', "\x1f\x8b gzip bytes");

        $response = $this->serve('/app.css', headers: ['Accept-Encoding' => $acceptEncoding]);

        self::assertSame($expected, $response['headers']['Content-Encoding'] ?? null);
        self::assertSame('text/css', $response['headers']['Content-Type'] ?? null);
        self::assertSame('Accept-Encoding', $response['headers']['Vary'] ?? null);
        $body = true === ($response['isBase64Encoded'] ?? false) ? base64_decode($response['body'], true) : $response['body'];
        self::assertSame(match ($expected) {
            'br' => 'brotli bytes',
            'gzip' => "\x1f\x8b gzip bytes",
            default => 'body { color: red; }',
        }, $body);
    }

    public function testEachEncodingHasItsOwnETag(): void
    {
        $this->write('app.css.gz', "\x1f\x8b gzip bytes");

        $plain = $this->serve('/app.css')['headers']['ETag'] ?? null;
        $gzip = $this->serve('/app.css', headers: ['Accept-Encoding' => 'gzip'])['headers']['ETag'] ?? null;

        self::assertNotSame($plain, $gzip);
        self::assertSame(304, $this->serve('/app.css', headers: ['Accept-Encoding' => 'gzip', 'If-None-Match' => (string) $gzip])['statusCode']);
        self::assertSame(200, $this->serve('/app.css', headers: ['If-None-Match' => (string) $gzip])['statusCode']);
    }

    public function testAPrecompressedVariantIsAlsoAFileOfItsOwn(): void
    {
        $this->write('app.css.gz', "\x1f\x8b gzip bytes");

        $response = $this->serve('/app.css.gz');

        self::assertSame("\x1f\x8b gzip bytes", base64_decode($response['body'], true));
        self::assertArrayNotHasKey('Content-Encoding', $response['headers']);
    }

    public function testCompressesTextualFilesOnTheFly(): void
    {
        $css = str_repeat('body { color: red; }', 100);
        $this->write('large.css', $css);

        $response = $this->serve('/large.css', headers: ['Accept-Encoding' => 'gzip'], compression: 'gzip');

        self::assertSame('gzip', $response['headers']['Content-Encoding'] ?? null);
        self::assertSame('Accept-Encoding', $response['headers']['Vary'] ?? null);
        self::assertTrue($response['isBase64Encoded'] ?? false);
        self::assertSame($css, gzdecode((string) base64_decode($response['body'], true)));
        self::assertArrayNotHasKey('Content-Encoding', $this->serve('/large.css', compression: 'gzip')['headers']);
    }

    public function testLeavesSmallAndAlreadyCompressedFilesUncompressed(): void
    {
        $this->write('large.png', str_repeat("\x89", 2048));

        self::assertArrayNotHasKey('Content-Encoding', $this->serve('/app.css', headers: ['Accept-Encoding' => 'gzip'], compression: 'gzip')['headers']);
        $png = $this->serve('/large.png', headers: ['Accept-Encoding' => 'gzip'], compression: 'gzip');
        self::assertArrayNotHasKey('Content-Encoding', $png['headers']);
        self::assertArrayNotHasKey('Vary', $png['headers']);
    }

    public function testPrefersAPrecompressedVariantToCompressingOnTheFly(): void
    {
        $this->write('large.css', str_repeat('a', 2048));
        $this->write('large.css.br', 'brotli bytes');

        $response = $this->serve('/large.css', headers: ['Accept-Encoding' => 'gzip, br'], compression: 'gzip');

        self::assertSame('br', $response['headers']['Content-Encoding'] ?? null);
    }

    public function testCompressedBodiesAreKeptWithinTheirCap(): void
    {
        $staticFiles = $this->staticFiles('gzip');
        $cache = new \ReflectionProperty(StaticFiles::class, 'compressed');
        // Hex digits of random bytes compress to about half their size: twelve such files fill the cache past its cap.
        $hex = bin2hex(random_bytes(3 * 1024 * 1024));
        $files = range(1, 12);
        foreach ($files as $name) {
            $this->write("$name.txt", $hex);
            $response = $staticFiles->serve(['path' => "/$name.txt", 'headers' => ['Accept-Encoding' => 'gzip']]);
            self::assertSame(200, $response['statusCode'] ?? null);
        }

        $cached = $cache->getValue($staticFiles);
        self::assertIsArray($cached);
        self::assertLessThan(\count($files), \count($cached));
        self::assertLessThanOrEqual(StaticFiles::COMPRESSION_CACHE_SIZE, array_sum(array_map(strlen(...), array_filter($cached, \is_string(...)))));
        $last = $staticFiles->serve(['path' => '/12.txt', 'headers' => ['Accept-Encoding' => 'gzip']]);
        self::assertSame($hex, gzdecode((string) base64_decode($last['body'] ?? '', true)));
    }

    public function testFilesTooLargeForAFunctionResponseAreA500AndLogged(): void
    {
        $this->write('large.bin', str_repeat("\xff", 5 * 1024 * 1024));
        $this->write('huge.txt', str_repeat('a', StaticFiles::MAX_RESPONSE_SIZE + 1));

        foreach (['/large.bin', '/huge.txt'] as $path) {
            $response = $this->serve($path);
            self::assertSame(500, $response['statusCode']);
            self::assertSame('Internal Server Error', $response['body']);
        }
        rewind($this->errorLog);
        $log = (string) stream_get_contents($this->errorLog);
        self::assertStringContainsString('Static File "/large.bin" is 6990508 bytes once encoded', $log);
        self::assertStringContainsString('Static File "/huge.txt"', $log);
    }

    public function testResponseSizeCountsTheBase64OfBinaryFiles(): void
    {
        $this->write('binary.bin', "\xff\xff\xff");

        self::assertSame(20, StaticFiles::responseSize($this->root.'/public/app.css'));
        self::assertSame(4, StaticFiles::responseSize($this->root.'/public/binary.bin'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidCompressions(): iterable
    {
        yield 'unknown encoding' => ['deflate'];
        yield 'true' => [true];
    }

    #[DataProvider('invalidCompressions')]
    public function testInvalidCompressionIsAnError(mixed $compression): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StaticFiles::create($this->root, $compression, $this->errorLog);
    }

    public function testBrotliCompressionNeedsTheBrotliExtension(): void
    {
        if (\function_exists('brotli_compress')) {
            self::markTestSkipped('The brotli extension is loaded.');
        }

        $this->expectExceptionMessage('the brotli extension is not loaded');

        StaticFiles::create($this->root, 'br', $this->errorLog);
    }

    #[RequiresPhpExtension('brotli')]
    public function testCompressesWithBrotli(): void
    {
        $this->write('large.css', str_repeat('a', 2048));

        $response = $this->serve('/large.css', headers: ['Accept-Encoding' => 'br'], compression: 'br');

        self::assertSame('br', $response['headers']['Content-Encoding'] ?? null);
    }

    private function write(string $path, string $content): void
    {
        new Filesystem()->dumpFile($this->root.'/public/'.$path, $content);
    }

    private function staticFiles(?string $compression = null): StaticFiles
    {
        return StaticFiles::create($this->root.'/public', $compression ?? false, $this->errorLog);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{statusCode: int, headers: array<string, string>, body: string, isBase64Encoded?: bool}
     */
    private function serve(string $path, string $method = 'GET', array $headers = [], ?string $compression = null): array
    {
        $response = $this->staticFiles($compression)->serve(['path' => $path, 'httpMethod' => $method, 'headers' => $headers]);
        self::assertNotNull($response, $path);
        // Static Files never repeat a header: single values keep the assertions short.
        $response['headers'] = array_map(static fn (string|array $value): string => \is_string($value) ? $value : implode(', ', $value), $response['headers'] ?? []);

        return $response;
    }
}
