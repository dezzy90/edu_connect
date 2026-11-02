<?php

namespace App\Services\Auth;

use RuntimeException;

final class ReviewerDemoPreview extends RuntimeException
{
    public function __construct(public readonly array $result)
    {
        parent::__construct('Synthetic provisioning preview rolled back.');
    }
}
