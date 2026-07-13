<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Exceptions;

/**
 * Thrown when the FCM HTTP request fails (network error, FCM error response, etc.).
 */
class SendingFailedException extends PushNotificationException
{
    /**
     * The raw FCM error response body, if available.
     */
    private ?string $fcmResponse;

    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $fcmResponse = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->fcmResponse = $fcmResponse;
    }

    /**
     * Returns the raw FCM HTTP response body for debugging.
     */
    public function getFcmResponse(): ?string
    {
        return $this->fcmResponse;
    }
}
