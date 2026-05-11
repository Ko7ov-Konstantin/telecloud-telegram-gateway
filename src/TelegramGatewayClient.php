<?php

namespace Telecloud\TelegramGateway;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;

final class TelegramGatewayClient
{
    public const DEFAULT_BASE_URL = 'https://gatewayapi.telegram.org';

    private ClientInterface $httpClient;

    public function __construct(
        private readonly string $token,
        ?ClientInterface $httpClient = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 12.0,
    ) {
        if (trim($token) === '') {
            throw new InvalidArgumentException('Telegram Gateway token is required.');
        }

        $this->httpClient = $httpClient ?: new Client();
    }

    /**
     * Checks whether Telegram Gateway can send a code to the phone.
     *
     * Important: Telegram may charge for a successful checkSendAbility result.
     *
     * @return array<string, mixed>
     */
    public function checkSendAbility(string $phone): array
    {
        $this->assertPhone($phone);

        return $this->post('checkSendAbility', [
            'phone_number' => $phone,
        ]);
    }

    /**
     * Sends a verification message.
     *
     * Supported options: code, code_length, ttl, payload, request_id,
     * callback_url, sender_username.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendVerificationMessage(string $phone, array $options = []): array
    {
        $this->assertPhone($phone);

        $body = [
            'phone_number' => $phone,
            'ttl' => $this->ttl($options['ttl'] ?? 300),
        ];

        if (isset($options['code']) && $options['code'] !== '') {
            $body['code'] = $this->code((string) $options['code']);
        } else {
            $body['code_length'] = $this->codeLength($options['code_length'] ?? 6);
        }

        foreach (['payload', 'request_id', 'callback_url', 'sender_username'] as $key) {
            if (! isset($options[$key]) || $options[$key] === '') {
                continue;
            }

            $body[$key] = (string) $options[$key];
        }

        if (isset($body['payload']) && strlen($body['payload']) > 128) {
            throw new InvalidArgumentException('Telegram Gateway payload must be 128 bytes or less.');
        }

        if (isset($body['callback_url']) && strlen($body['callback_url']) > 256) {
            throw new InvalidArgumentException('Telegram Gateway callback_url must be 256 bytes or less.');
        }

        return $this->post('sendVerificationMessage', $body);
    }

    /**
     * Checks delivery/verification status and optionally validates a user code.
     *
     * @return array<string, mixed>
     */
    public function checkVerificationStatus(string $requestId, ?string $code = null): array
    {
        $body = [
            'request_id' => $this->nonEmpty($requestId, 'request_id'),
        ];

        if ($code !== null && $code !== '') {
            $body['code'] = $this->code($code);
        }

        return $this->post('checkVerificationStatus', $body);
    }

    public function revokeVerificationMessage(string $requestId): bool
    {
        $response = $this->post('revokeVerificationMessage', [
            'request_id' => $this->nonEmpty($requestId, 'request_id'),
        ]);

        return (bool) (self::value($response, 'result') ?? $response['ok'] ?? false);
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function requestId(array $response): ?string
    {
        $requestId = self::value($response, 'result.request_id')
            ?? self::value($response, 'request_id')
            ?? self::value($response, 'verification_message.request_id');

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function deliveryStatus(array $response): ?string
    {
        $status = self::value($response, 'result.delivery_status.status')
            ?? self::value($response, 'delivery_status.status')
            ?? self::value($response, 'result.status')
            ?? self::value($response, 'status');

        return is_string($status) && $status !== '' ? strtolower($status) : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function verificationStatus(array $response): ?string
    {
        $status = self::value($response, 'result.verification_status.status')
            ?? self::value($response, 'verification_status.status')
            ?? self::value($response, 'result.status')
            ?? self::value($response, 'status');

        return is_string($status) && $status !== '' ? strtolower($status) : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function isCodeValid(array $response): bool
    {
        return self::verificationStatus($response) === 'code_valid';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $method, array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($method), [
                'http_errors' => false,
                'timeout' => $this->timeout,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (GuzzleException $exception) {
            throw TelegramGatewayException::transport($method, $exception);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TelegramGatewayException::invalidJson($method);
        }

        if (! is_array($decoded)) {
            throw TelegramGatewayException::invalidJson($method);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300 || ($decoded['ok'] ?? true) === false) {
            throw TelegramGatewayException::api($method, $statusCode, $decoded);
        }

        return $decoded;
    }

    private function url(string $method): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($method, '/');
    }

    private function assertPhone(string $phone): void
    {
        if (! preg_match('/^\+[1-9]\d{8,14}$/', $phone)) {
            throw new InvalidArgumentException('Phone must be in E.164 format.');
        }
    }

    private function code(string $code): string
    {
        if (! preg_match('/^\d{4,8}$/', $code)) {
            throw new InvalidArgumentException('Telegram Gateway code must be a numeric string between 4 and 8 digits.');
        }

        return $code;
    }

    private function codeLength(mixed $value): int
    {
        $length = (int) $value;

        if ($length < 4 || $length > 8) {
            throw new InvalidArgumentException('Telegram Gateway code_length must be between 4 and 8.');
        }

        return $length;
    }

    private function ttl(mixed $value): int
    {
        $ttl = (int) $value;

        if ($ttl < 30 || $ttl > 3600) {
            throw new InvalidArgumentException('Telegram Gateway ttl must be between 30 and 3600 seconds.');
        }

        return $ttl;
    }

    private function nonEmpty(string $value, string $field): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Telegram Gateway {$field} is required.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function value(array $data, string $path): mixed
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
