<?php

namespace Telecloud\TelegramGateway\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Telecloud\TelegramGateway\TelegramGatewayClient;
use Telecloud\TelegramGateway\TelegramGatewayException;
use Telecloud\TelegramGateway\TelegramGatewayResponseSanitizer;
use Telecloud\TelegramGateway\TelegramGatewaySignature;

final class TelegramGatewayClientTest extends TestCase
{
    public function testItSendsVerificationMessageWithBearerTokenAndOptions(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'request_id' => 'req-1',
                    'delivery_status' => ['status' => 'sent'],
                ],
            ])),
        ], $history);

        $response = $client->sendVerificationMessage('+995555123456', [
            'code_length' => 6,
            'ttl' => 300,
            'payload' => 'payload-1',
            'callback_url' => 'https://example.test/callback',
        ]);

        $this->assertSame('req-1', TelegramGatewayClient::requestId($response));
        $this->assertSame('sent', TelegramGatewayClient::deliveryStatus($response));
        $this->assertCount(1, $history);
        $this->assertSame('Bearer test-token', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('/sendVerificationMessage', $history[0]['request']->getUri()->getPath());
        $this->assertEquals([
            'phone_number' => '+995555123456',
            'code_length' => 6,
            'ttl' => 300,
            'payload' => 'payload-1',
            'callback_url' => 'https://example.test/callback',
        ], json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testItChecksVerificationStatusAndDetectsValidCode(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'request_id' => 'req-1',
                    'verification_status' => ['status' => 'code_valid'],
                ],
            ])),
        ], $history);

        $response = $client->checkVerificationStatus('req-1', '123456');

        $this->assertTrue(TelegramGatewayClient::isCodeValid($response));
        $this->assertSame('/checkVerificationStatus', $history[0]['request']->getUri()->getPath());
        $this->assertSame([
            'request_id' => 'req-1',
            'code' => '123456',
        ], json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testItCanUseTokenProvidedForCurrentSite(): void
    {
        $history = [];
        $client = $this->clientWithoutToken([
            new Response(200, [], json_encode(['ok' => true, 'result' => ['can_send' => true]])),
        ], $history);

        $client->withToken('site-token')->checkSendAbility('+995555123456');

        $this->assertSame('Bearer site-token', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testItThrowsSafeExceptionForTelegramApiErrors(): void
    {
        $client = $this->client([
            new Response(400, [], json_encode([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: invalid phone',
            ])),
        ]);

        $this->expectException(TelegramGatewayException::class);
        $this->expectExceptionMessage('Telegram Gateway API request failed.');

        try {
            $client->checkSendAbility('+995555123456');
        } catch (TelegramGatewayException $exception) {
            $this->assertSame(400, $exception->statusCode());
            $this->assertStringNotContainsString('test-token', $exception->getMessage());
            throw $exception;
        }
    }

    public function testItValidatesTelegramCallbackSignature(): void
    {
        $body = '{"request_id":"req-1"}';
        $timestamp = '1710000000';
        $token = 'test-token';
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, hash('sha256', $token, true));

        $this->assertTrue(TelegramGatewaySignature::isValid($body, $timestamp, $signature, $token, 600, 1710000001));
        $this->assertFalse(TelegramGatewaySignature::isValid($body, $timestamp, 'bad-signature', $token, 600, 1710000001));
        $this->assertFalse(TelegramGatewaySignature::isValid($body, $timestamp, $signature, $token, 600, 1710001001));
    }

    public function testItSanitizesSensitiveGatewayResponseFields(): void
    {
        $sanitized = TelegramGatewayResponseSanitizer::sanitize([
            'phone_number' => '+995555123456',
            'result' => [
                'phone' => '+995555123456',
                'verification_status' => [
                    'status' => 'code_invalid',
                    'code_entered' => '123456',
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('phone_number', $sanitized);
        $this->assertArrayHasKey('phone_number_hash', $sanitized);
        $this->assertArrayNotHasKey('phone', $sanitized['result']);
        $this->assertArrayNotHasKey('code_entered', $sanitized['result']['verification_status']);
        $this->assertSame('code_invalid', $sanitized['result']['verification_status']['status']);
    }

    /**
     * @param array<int, Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history = []): TelegramGatewayClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new TelegramGatewayClient(
            'test-token',
            new Client(['handler' => $stack]),
            'https://gatewayapi.telegram.org'
        );
    }

    /**
     * @param array<int, Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function clientWithoutToken(array $responses, array &$history = []): TelegramGatewayClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new TelegramGatewayClient(
            httpClient: new Client(['handler' => $stack]),
            baseUrl: 'https://gatewayapi.telegram.org'
        );
    }
}
