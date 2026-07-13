<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Tests;

use PHPUnit\Framework\TestCase;
use SwanFlutter\NotificationMaster\Exceptions\InvalidMessageException;
use SwanFlutter\NotificationMaster\Message;

class MessageTest extends TestCase
{
    public function test_message_create_returns_instance(): void
    {
        $this->assertInstanceOf(Message::class, Message::create());
    }

    public function test_fluent_builder_sets_token(): void
    {
        $message = Message::create()
            ->toToken('test-token')
            ->title('Hello')
            ->body('World');

        $this->assertSame('test-token', $message->getToken());
        $this->assertNull($message->getTopic());
        $this->assertNull($message->getCondition());
    }

    public function test_fluent_builder_sets_topic(): void
    {
        $message = Message::create()->toTopic('news');

        $this->assertSame('news', $message->getTopic());
        $this->assertNull($message->getToken());
    }

    public function test_fluent_builder_sets_condition(): void
    {
        $message = Message::create()->toCondition("'sports' in topics");

        $this->assertSame("'sports' in topics", $message->getCondition());
        $this->assertNull($message->getToken());
        $this->assertNull($message->getTopic());
    }

    public function test_to_array_includes_token_and_notification(): void
    {
        $payload = Message::create()
            ->toToken('device-token')
            ->title(title: 'Hello')
            ->body(body: 'World')
            ->toArray();

        $this->assertSame('device-token', $payload['token']);
        $this->assertSame('Hello', $payload['notification']['title']);
        $this->assertSame('World', $payload['notification']['body']);
    }

    public function test_to_array_includes_data(): void
    {
        $payload = Message::create()
            ->toToken('device-token')
            ->title('Hi')
            ->data(['key' => 'value'])
            ->toArray();

        $this->assertSame('value', $payload['data']['key']);
    }

    public function test_to_array_casts_data_values_to_string(): void
    {
        $payload = Message::create()
            ->toToken('device-token')
            ->title('Hi')
            ->data(['count' => 42])
            ->toArray();

        $this->assertSame('42', $payload['data']['count']);
    }

    public function test_validate_throws_when_no_target(): void
    {
        $this->expectException(InvalidMessageException::class);

        Message::create()->title('Hello')->body('World')->validate();
    }

    public function test_validate_throws_when_no_content(): void
    {
        $this->expectException(InvalidMessageException::class);

        Message::create()->toToken('device-token')->validate();
    }

    public function test_target_is_mutually_exclusive(): void
    {
        $message = Message::create()
            ->toToken('token-1')
            ->toTopic('news');

        $this->assertNull($message->getToken());
        $this->assertSame('news', $message->getTopic());
    }

    public function test_ttl_applied_to_android_and_webpush(): void
    {
        $payload = Message::create()
            ->toToken('device-token')
            ->title('Hi')
            ->ttl(3600)
            ->toArray();

        $this->assertSame('3600s', $payload['android']['ttl']);
        $this->assertSame('3600', $payload['webpush']['headers']['TTL']);
    }

    public function test_named_arguments_work(): void
    {
        $payload = Message::create()
            ->toToken(token: 'device-token')
            ->title(title: 'Hello')
            ->body(body: 'World')
            ->toArray();

        $this->assertSame('device-token', $payload['token']);
        $this->assertSame('Hello', $payload['notification']['title']);
    }
}
