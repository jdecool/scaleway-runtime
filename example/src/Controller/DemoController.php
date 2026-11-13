<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController
{
    // Static, so it survives the Kernel's service reset and only a new process starts it over.
    private static int $invocations = 0;

    #[Route('/', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['routes' => ['/hello', '/echo', '/cookies', '/binary', '/stream', '/counter']]);
    }

    // CRON Invocations arrive as a POST on "/" with the trigger's arguments as body.
    #[Route('/', methods: ['POST'])]
    public function cron(Request $request, LoggerInterface $logger): JsonResponse
    {
        $logger->info('CRON Invocation received', ['body' => $request->getContent()]);

        return new JsonResponse(['cron' => true, 'args' => json_decode($request->getContent(), true)]);
    }

    #[Route('/hello', methods: ['GET'])]
    public function hello(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => \sprintf('Hello %s!', $request->query->getString('name', 'world'))]);
    }

    #[Route('/echo')]
    public function echo(Request $request): JsonResponse
    {
        return new JsonResponse([
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'headers' => array_map(static fn (array $values) => implode(', ', $values), $request->headers->all()),
            'clientIp' => $request->getClientIp(),
            'secure' => $request->isSecure(),
            'cookies' => $request->cookies->all(),
            // A binary body would make the JSON encoding fail, its hash still tells whether it arrived intact.
            'body' => mb_check_encoding($request->getContent(), 'UTF-8') ? $request->getContent() : null,
            'bodySha256' => hash('sha256', $request->getContent()),
            'form' => $request->request->all(),
            'files' => array_map(static fn (UploadedFile $file) => [
                'name' => $file->getClientOriginalName(),
                'type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getPathname()),
            ], array_filter($request->files->all(), static fn ($file) => $file instanceof UploadedFile)),
        ]);
    }

    #[Route('/cookies', methods: ['GET'])]
    public function cookies(): JsonResponse
    {
        $response = new JsonResponse(['cookies' => ['first', 'second', 'third']]);
        $response->headers->setCookie(Cookie::create('first', '1'));
        $response->headers->setCookie(Cookie::create('second', 'two words'));
        $response->headers->setCookie(Cookie::create('third', '3', path: '/cookies', httpOnly: false));

        return $response;
    }

    #[Route('/binary', methods: ['GET'])]
    public function binary(): Response
    {
        return new Response(implode('', array_map(chr(...), range(0, 255))), headers: [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="bytes.bin"',
        ]);
    }

    #[Route('/stream', methods: ['GET'])]
    public function stream(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {
            foreach (range(1, 5) as $chunk) {
                echo "chunk {$chunk}\n";
                flush();
            }
        }, headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    #[Route('/counter', methods: ['GET'])]
    public function counter(): JsonResponse
    {
        return new JsonResponse(['invocations' => ++self::$invocations, 'pid' => getmypid()]);
    }
}
