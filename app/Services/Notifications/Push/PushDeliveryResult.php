<?php

namespace App\Services\Notifications\Push;

class PushDeliveryResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $messageId = null,
        public readonly array $providerResponse = [],
        public readonly ?string $error = null,
        public readonly bool $invalidToken = false,
    ) {
    }

    public static function sent(?string $messageId = null, array $providerResponse = []): self
    {
        return new self(true, $messageId, $providerResponse);
    }

    public static function failed(string $error, array $providerResponse = [], bool $invalidToken = false): self
    {
        return new self(false, null, $providerResponse, $error, $invalidToken);
    }
}
