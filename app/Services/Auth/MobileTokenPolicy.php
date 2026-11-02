<?php

namespace App\Services\Auth;

use App\Models\V2\ParentAccount;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileTokenPolicy
{
    public static function persistent(PersonalAccessToken $token): bool
    {
        return $token->tokenable_type === (new ParentAccount)->getMorphClass()
            && $token->abilities === ['mobile:parent'] && $token->expires_at === null;
    }
}
