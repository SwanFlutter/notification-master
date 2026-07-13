<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Exceptions;

/**
 * Thrown when the Firebase service account file is missing or malformed.
 */
class InvalidCredentialsException extends PushNotificationException {}
