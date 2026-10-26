<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Middleware;

use Closure;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// Deleting a custom field also deletes all its values, so every intro end would be lost.
final readonly class ProtectIntroField implements MiddlewareInterface
{
    /**
     * @param Closure(): ?int $fieldId
     */
    public function __construct(
        private Closure $fieldId,
        private string $basePath = ''
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = substr($request->getUri()->getPath(), strlen(rtrim($this->basePath, '/')));
        if (
            !in_array($request->getMethod(), ['DELETE', 'PUT'], true)
            || !preg_match('~^/api/admin/custom_field/(\d+)$~D', $path, $match)
            || (int)$match[1] !== ($this->fieldId)()
        ) {
            return $handler->handle($request);
        }
        if ($request->getMethod() === 'DELETE') {
            return self::refuse('The Intro End field is used by the Sweepers plugin and cannot be deleted.');
        }
        $body = json_decode((string)$request->getBody(), true);
        $request->getBody()->rewind();
        if (is_array($body) && array_key_exists('short_name', $body) && $body['short_name'] !== 'intro_end') {
            return self::refuse('The Sweepers plugin needs the programmatic name intro_end.');
        }

        return $handler->handle($request);
    }

    private static function refuse(string $message): ResponseInterface
    {
        return new Response(409, ['Content-Type' => 'application/json'], json_encode([
            'code' => 409,
            'type' => 'Conflict',
            'message' => $message,
            'formatted_message' => $message,
            'success' => false,
        ], JSON_THROW_ON_ERROR));
    }
}
