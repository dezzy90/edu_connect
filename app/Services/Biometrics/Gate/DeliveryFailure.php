<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

final class DeliveryFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $permanent = false)
    {
        parent::__construct($reason);
    }
}
