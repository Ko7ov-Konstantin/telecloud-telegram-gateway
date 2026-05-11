# Telecloud Telegram Gateway

Small framework-agnostic PHP client for the official Telegram Gateway API.

The package does not store tokens, verification requests, phone numbers, or codes.
Storage, rate limits, sessions, and database schema stay in the consuming project.

## Install

```bash
composer require telecloud/telegram-gateway
```

For local development before publishing:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../telecloud-telegram-gateway",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "telecloud/telegram-gateway": "*"
  }
}
```

## Usage

```php
use Telecloud\TelegramGateway\TelegramGatewayClient;

$gateway = new TelegramGatewayClient(
    token: getenv('TELEGRAM_GATEWAY_TOKEN'),
    baseUrl: getenv('TELEGRAM_GATEWAY_BASE_URL') ?: TelegramGatewayClient::DEFAULT_BASE_URL,
);

$send = $gateway->sendVerificationMessage('+995555123456', [
    'code_length' => 6,
    'ttl' => 300,
    'payload' => 'internal-payload',
    'callback_url' => 'https://example.com/api/telegram-gateway/callback',
]);

$requestId = TelegramGatewayClient::requestId($send);

$check = $gateway->checkVerificationStatus($requestId, '123456');

if (TelegramGatewayClient::isCodeValid($check)) {
    // Mark the phone as verified in your application.
}
```

## Callback Signature

```php
use Telecloud\TelegramGateway\TelegramGatewaySignature;

$isValid = TelegramGatewaySignature::isValid(
    body: $rawBody,
    timestamp: $requestTimestamp,
    signature: $requestSignature,
    token: getenv('TELEGRAM_GATEWAY_TOKEN'),
);
```

## Safe Response Storage

Before saving Telegram responses to a database or logs, sanitize them:

```php
use Telecloud\TelegramGateway\TelegramGatewayResponseSanitizer;

$safeResponse = TelegramGatewayResponseSanitizer::sanitize($telegramResponse);
```

The sanitizer hashes phone fields and removes OTP/code/token fields.

## Notes

- `checkSendAbility()` may charge the account if Telegram confirms that the number can be contacted.
- `sendVerificationMessage()` uses Telegram-generated codes unless you pass a numeric `code`.
- `ttl` is validated in the official range: 30 to 3600 seconds.
- `code_length` is validated in the official range: 4 to 8 digits.

## Tests

```bash
composer install
composer test
```
