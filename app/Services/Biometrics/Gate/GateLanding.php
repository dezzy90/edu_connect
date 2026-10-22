<?php

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;

final class GateLanding
{
    public function path(AdminUser $actor): string
    {
        if (app(\App\Services\Biometrics\Devices\RegistryAccess::class)->canEnter($actor)) {
            return '/admin/gate/devices';
        }

        return app(SetupAccess::class)->canEnter($actor) ? '/admin/gate/setup' : '/admin/gate';
    }
}
