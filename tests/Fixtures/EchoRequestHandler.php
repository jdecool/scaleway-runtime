<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A PSR-15 Application describing the PSR-7 request it gets, with the response features a Function Response must keep.
 */
final class EchoRequestHandler implements RequestHandlerInterface, ResetInterface
{
    public int $handled = 0;
    public int $resets = 0;
    public ?ServerRequestInterface $lastRequest = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->handled;
        $this->lastRequest = $request;
        if ('/throw' === $request->getUri()->getPath()) {
            throw new \RuntimeException('Escaped on purpose.');
        }

        $files = array_map(static fn (UploadedFileInterface $file): array => [
            'name' => $file->getClientFilename(),
            'content' => (string) $file->getStream(),
        ], array_filter($request->getUploadedFiles(), static fn (mixed $file): bool => $file instanceof UploadedFileInterface));

        $body = json_encode([
            'uri' => (string) $request->getUri(),
            'remoteAddr' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            'query' => $request->getQueryParams(),
            'parsedBody' => $request->getParsedBody(),
            'body' => (string) $request->getBody(),
            'files' => $files,
            'handled' => $this->handled,
            'resets' => $this->resets,
            'pid' => getmypid(),
        ], \JSON_THROW_ON_ERROR);

        return new Response(201, [
            'Content-Type' => 'application/json',
            'X-Multi' => ['a', 'b'],
            'Set-Cookie' => ['first=1; path=/', 'second=2; path=/'],
        ], $body);
    }

    public function reset(): void
    {
        ++$this->resets;
    }
}
