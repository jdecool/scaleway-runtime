<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

use Symfony\Component\Mime\MimeTypes;

/**
 * Answers HTTP Invocations asking for a Static File, before the Application is built
 * (see docs/adr/0009-serve-static-files-from-the-function.md).
 *
 * Only GET and HEAD requests for a regular file under the web root are served, never a .php file nor a path with a
 * segment starting with a dot; everything else goes to the Application, as with nginx's try_files.
 *
 * @phpstan-import-type FunctionResponse from HttpConverter
 */
final class StaticFiles
{
    /** Encodings of the precompressed variants, in order of preference, with their file extension. */
    public const array PRECOMPRESSED = ['br' => '.br', 'gzip' => '.gz'];

    /** The values of the "static_compression" runtime option, but false. */
    public const array COMPRESSIONS = ['gzip', 'br'];

    // The platform enforces no limit, but larger responses intermittently time out (docs/probe-results.md#size-and-encodings).
    public const int MAX_RESPONSE_SIZE = 6 * 1024 * 1024;

    /** Smaller files gain less from compression than the headers it adds cost. */
    public const int COMPRESSION_MIN_SIZE = 1024;

    /** Bounds the memory the compressed bodies kept across Invocations may take in a Warm Process. */
    public const int COMPRESSION_CACHE_SIZE = 32 * 1024 * 1024;

    /** Types already compressed by their format are left out: compressing them again costs CPU for nothing. */
    private const array COMPRESSIBLE_TYPES = ['application/javascript', 'application/json', 'application/xml', 'application/wasm', 'image/svg+xml'];

    private ?string $root = null;

    /** @var array<string, string> */
    private array $etags = [];

    /** @var array<string, string> */
    private array $compressed = [];

    private int $compressedSize = 0;

    /** @var array<string, array<string, true>> the digested public paths of each manifest.json, by manifest file */
    private array $manifests = [];

    /**
     * @param string           $webRoot     the directory holding the Static Files
     * @param 'gzip'|'br'|null $compression the encoding to compress textual files with on the fly, if any
     * @param resource         $errorLog
     */
    public function __construct(
        private readonly string $webRoot,
        private readonly ?string $compression,
        private readonly mixed $errorLog,
    ) {
    }

    /**
     * @param mixed    $compression the "static_compression" runtime option
     * @param resource $errorLog
     */
    public static function create(string $webRoot, mixed $compression, mixed $errorLog): self
    {
        if (false === $compression || null === $compression) {
            return new self($webRoot, null, $errorLog);
        }
        if (!\in_array($compression, self::COMPRESSIONS, true)) {
            throw new \InvalidArgumentException(\sprintf('The "static_compression" runtime option must be false, "gzip" or "br", "%s" given.', get_debug_type($compression)));
        }
        // Falling back to another encoding would hide that the function serves something else than what was asked.
        if ('br' === $compression && !\function_exists('brotli_compress')) {
            throw new \LogicException('The "static_compression" runtime option is "br", but the brotli extension is not loaded.');
        }
        if ('gzip' === $compression && !\function_exists('gzencode')) {
            throw new \LogicException('The "static_compression" runtime option is "gzip", but the zlib extension is not loaded.');
        }

        return new self($webRoot, $compression, $errorLog);
    }

    /**
     * @param array<mixed> $event
     *
     * @return FunctionResponse|null null when the Invocation is not for a Static File
     */
    public function serve(array $event): ?array
    {
        $method = strtoupper(\is_string($event['httpMethod'] ?? null) ? $event['httpMethod'] : (\is_string($event['method'] ?? null) ? $event['method'] : 'GET'));
        $path = \is_string($event['path'] ?? null) ? rawurldecode($event['path']) : '';
        if (!\in_array($method, ['GET', 'HEAD'], true) || null === $file = $this->resolve($path)) {
            return null;
        }

        $headers = array_change_key_case(\is_array($event['headers'] ?? null) ? $event['headers'] : []);
        $type = MimeTypes::getDefault()->getMimeTypes(pathinfo($file, \PATHINFO_EXTENSION))[0] ?? 'application/octet-stream';
        $compressible = null !== $this->compression && $this->isCompressible($type);
        [$encoding, $bodyFile] = $this->negotiate($file, \is_string($headers['accept-encoding'] ?? null) ? $headers['accept-encoding'] : '', $compressible);

        $etag = $this->etag($bodyFile ?? $file, $encoding);
        $responseHeaders = [
            'Content-Type' => $type,
            'Cache-Control' => $this->isDigested($path, $file) ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($compressible || $this->hasVariant($file)) {
            $responseHeaders['Vary'] = 'Accept-Encoding';
        }
        if (null !== $encoding) {
            $responseHeaders['Content-Encoding'] = $encoding;
        }

        if (self::matches(\is_string($headers['if-none-match'] ?? null) ? $headers['if-none-match'] : '', $etag)) {
            unset($responseHeaders['Content-Type']);

            return ['statusCode' => 304, 'headers' => $responseHeaders, 'body' => ''];
        }
        if ('HEAD' === $method) {
            return ['statusCode' => 200, 'headers' => $responseHeaders, 'body' => ''];
        }

        $compressing = null === $bodyFile && null !== $encoding;
        // Checked before reading, so a large file cannot exhaust the memory of the Warm Process; a file compressed
        // on the fly may shrink below the limit, so it gets more room.
        $size = (int) filesize($bodyFile ?? $file);
        if ($size > ($compressing ? 4 : 1) * self::MAX_RESPONSE_SIZE) {
            return $this->tooLarge($path, $size);
        }

        $body = $compressing ? $this->compress($file, $encoding) : (string) file_get_contents($bodyFile ?? $file);
        $base64 = null !== $encoding || 1 !== preg_match('//u', $body);
        $size = $base64 ? 4 * (int) ceil(\strlen($body) / 3) : \strlen($body);
        if ($size > self::MAX_RESPONSE_SIZE) {
            return $this->tooLarge($path, $size);
        }

        return $base64
            ? ['statusCode' => 200, 'headers' => $responseHeaders, 'body' => base64_encode($body), 'isBase64Encoded' => true]
            : ['statusCode' => 200, 'headers' => $responseHeaders, 'body' => $body];
    }

    /**
     * @return FunctionResponse
     */
    private function tooLarge(string $path, int $size): array
    {
        // The platform would answer an opaque error: the log says which file and why.
        fwrite($this->errorLog, \sprintf('Static File "%s" is %d bytes once encoded, over the %d bytes a Function Response may carry.', $path, $size, self::MAX_RESPONSE_SIZE).\PHP_EOL);

        return ['statusCode' => 500, 'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'], 'body' => 'Internal Server Error'];
    }

    /**
     * The size a file takes in a Function Response, base64 included, for scaleway-package to warn about.
     */
    public static function responseSize(string $file): int
    {
        $size = (int) filesize($file);
        if ($size > self::MAX_RESPONSE_SIZE) {
            return $size;
        }

        return 1 === preg_match('//u', (string) file_get_contents($file)) ? $size : 4 * (int) ceil($size / 3);
    }

    private function resolve(string $path): ?string
    {
        if (str_contains($path, "\0") || str_ends_with(strtolower($path), '.php')) {
            return null;
        }
        // Dot segments cover "..", and hidden files such as .htaccess or .env that a web server would not serve either;
        // /.well-known/ is the one public prefix starting with a dot (RFC 8615).
        foreach (explode('/', ltrim($path, '/')) as $i => $segment) {
            if (str_starts_with($segment, '.') && (0 !== $i || '.well-known' !== $segment)) {
                return null;
            }
        }

        $this->root ??= realpath($this->webRoot) ?: '';
        if ('' === $this->root) {
            return null;
        }
        // A symbolic link leading out of the web root is not served either.
        $file = realpath($this->root.'/'.ltrim($path, '/'));

        return false !== $file && str_starts_with($file, $this->root.\DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    /**
     * Picks the encoding the client accepts, a precompressed variant first.
     *
     * @return array{?string, ?string} the encoding, and the precompressed file holding the body if any
     */
    private function negotiate(string $file, string $acceptEncoding, bool $compressible): array
    {
        $accepted = self::acceptedEncodings($acceptEncoding);
        foreach (self::PRECOMPRESSED as $encoding => $extension) {
            if ($accepted($encoding) && is_file($file.$extension)) {
                return [$encoding, $file.$extension];
            }
        }
        if ($compressible && \is_string($this->compression) && $accepted($this->compression) && filesize($file) >= self::COMPRESSION_MIN_SIZE) {
            return [$this->compression, null];
        }

        return [null, null];
    }

    /**
     * Only q=0 refuses an encoding: the order of preference is the runtime's, not the client's weights.
     *
     * @return \Closure(string): bool
     */
    private static function acceptedEncodings(string $header): \Closure
    {
        $weights = [];
        foreach (explode(',', $header) as $item) {
            $parts = explode(';', $item);
            $name = strtolower(trim($parts[0]));
            if ('' === $name) {
                continue;
            }
            $weights[$name] = 1.0;
            foreach (\array_slice($parts, 1) as $parameter) {
                if (1 === preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $match)) {
                    $weights[$name] = (float) $match[1];
                }
            }
        }

        return static fn (string $encoding): bool => ($weights[$encoding] ?? $weights['*'] ?? 0.0) > 0.0;
    }

    private function hasVariant(string $file): bool
    {
        return array_any(self::PRECOMPRESSED, static fn (string $extension): bool => is_file($file.$extension));
    }

    private function isCompressible(string $type): bool
    {
        return str_starts_with($type, 'text/') || \in_array($type, self::COMPRESSIBLE_TYPES, true)
            || str_ends_with($type, '+json') || str_ends_with($type, '+xml');
    }

    private function compress(string $file, string $encoding): string
    {
        $key = $this->key($file).':'.$encoding;
        if (isset($this->compressed[$key])) {
            return $this->compressed[$key];
        }

        $content = (string) file_get_contents($file);
        $body = (string) ('br' === $encoding ? brotli_compress($content) : gzencode($content));
        // Past the cap, files are compressed again for each Invocation rather than growing the Warm Process.
        if ($this->compressedSize + \strlen($body) <= self::COMPRESSION_CACHE_SIZE) {
            $this->compressed[$key] = $body;
            $this->compressedSize += \strlen($body);
        }

        return $body;
    }

    /**
     * A strong ETag of the bytes sent, each encoding having its own as its bytes differ.
     */
    private function etag(string $file, ?string $encoding): string
    {
        $key = $this->key($file);
        $this->etags[$key] ??= hash_file('xxh128', $file) ?: '';

        return '"'.$this->etags[$key].(null === $encoding ? '' : '-'.$encoding).'"';
    }

    /**
     * Files written at runtime, such as uploads, may change within a Warm Process: their key changes with them.
     */
    private function key(string $file): string
    {
        clearstatcache(true, $file);

        return $file.':'.filemtime($file).':'.filesize($file);
    }

    /**
     * Weak comparison, as RFC 9110 asks for If-None-Match.
     */
    private static function matches(string $ifNoneMatch, string $etag): bool
    {
        if ('*' === trim($ifNoneMatch)) {
            return true;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ((str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * A file is cached for good only when a manifest.json above it, as written by AssetMapper or Webpack Encore, maps a
     * logical path to it under another name: its name then changes with its content. Guessing a digest from the name
     * alone would also match plain names such as "font-awesome.css".
     */
    private function isDigested(string $path, string $file): bool
    {
        $public = '/'.ltrim($path, '/');
        for ($dir = \dirname($file); str_starts_with($dir.\DIRECTORY_SEPARATOR, (string) $this->root.\DIRECTORY_SEPARATOR); $dir = \dirname($dir)) {
            if (isset($this->manifest($dir.'/manifest.json')[$public])) {
                return true;
            }
            if ($dir === $this->root) {
                break;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function manifest(string $file): array
    {
        if (!isset($this->manifests[$file])) {
            $digested = [];
            $entries = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            foreach (\is_array($entries) ? $entries : [] as $logical => $public) {
                if (!\is_string($public)) {
                    continue;
                }
                // Assets served from a CDN or with a base path are listed as URLs: only their path is ours.
                $publicPath = parse_url($public, \PHP_URL_PATH);
                if (\is_string($publicPath) && basename($publicPath) !== basename((string) $logical)) {
                    $digested['/'.ltrim($publicPath, '/')] = true;
                }
            }
            $this->manifests[$file] = $digested;
        }

        return $this->manifests[$file];
    }
}
