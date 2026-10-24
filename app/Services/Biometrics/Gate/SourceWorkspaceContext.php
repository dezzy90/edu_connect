<?php

namespace App\Services\Biometrics\Gate;

/** Request-scoped permission to use a source-only audit identity. Never a login session. */
final class SourceWorkspaceContext
{
    public ?int $actorId = null;
    /** Only entries for restricted class-master scopes are present; an empty list denies every student. */
    public array $studentsBySchool = [];
    public array $permissionsBySchool = [];

    public function allows(\App\Models\AdminUser $actor, int $school, string $permission): bool
    {
        return $this->actorId === (int) $actor->id && in_array($permission, $this->permissionsBySchool[$school] ?? [], true);
    }
}
