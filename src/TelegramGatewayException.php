<?php

namespace Telecloud\TelegramGateway;

use RuntimeException;
use Throwable;

final class TelegramGatewayException extends RuntimeException
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        string $message,
        private readonly ?string $method = null,
        private readonly ?int $statusCode = null,
        private readonly array $response = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function api(string $method, int $statusCode, array $response = []): self
    {
        return new self(
            'Telegram Gateway API request failed.',
            $method,
            $statusCode,
            TelegramGatewayResponseSanitizer::sanitize($response)
        );
    }

    public static function transport(string $method, \Throwable $previous): self
    {
        return new self('Telegram Gateway request failed.', $method, null, [], $previous);
    }

    public static function invalidJson(string $method): self
    {
        return new self('Telegram Gateway returned invalid JSON.', $method);
    }

    public function method(): ?string
    {
        return $this->method;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function response(): array
    {
        return $this->response;
    }
}
