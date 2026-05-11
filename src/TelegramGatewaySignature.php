<?php

namespace Telecloud\TelegramGateway;

final class TelegramGatewaySignature
{
    public static function isValid(
        string $body,
        string $timestamp,
        string $signature,
        string $token,
        int $maxAgeSeconds = 600,
        ?int $now = null,
    ): bool {
        if ($token === '' || ! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        $now ??= time();
        $submittedAt = (int) $timestamp;

        if ($maxAgeSeconds > 0 && abs($now - $submittedAt) > $maxAgeSeconds) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp . "\n" . $body,
            hash('sha256', $token, true)
        );

        return hash_equals($expected, strtolower($signature));
    }
}
