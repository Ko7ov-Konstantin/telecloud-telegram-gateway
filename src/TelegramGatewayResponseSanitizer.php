<?php

namespace Telecloud\TelegramGateway;

final class TelegramGatewayResponseSanitizer
{
    private const HASH_KEYS = [
        'phone',
        'phone_number',
    ];

    private const DROP_KEYS = [
        'authorization',
        'token',
        'api_token',
        'code',
        'code_entered',
    ];

    /**
     * Removes secrets and OTP values while keeping enough data for diagnostics.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function sanitize(array $response): array
    {
        foreach ($response as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (is_array($value)) {
                $response[$key] = self::sanitize($value);
                continue;
            }

            if (in_array($normalizedKey, self::HASH_KEYS, true)) {
                $response[$key . '_hash'] = hash('sha256', (string) $value);
                unset($response[$key]);
                continue;
            }

            if (in_array($normalizedKey, self::DROP_KEYS, true)) {
                unset($response[$key]);
            }
        }

        return $response;
    }
}
