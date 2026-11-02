<?php

namespace App\Services\Auth;

use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;

final class MobileOtpEligibility
{
    public function __construct(private MobileOtpPhone $phones) {}

    /** A proof authorizes account creation/login only, never a child link. */
    public function resolve(#[\SensitiveParameter] string $phone, ?array $proof = null, bool $lock = false): ?array
    {
        $parents = $this->phones->matching(ParentAccount::withTrashed(), 'phone', $phone)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->filter(fn ($parent) => $this->phones->normalize($parent->phone) === $phone);

        if ($parents->isNotEmpty()) {
            $parent = $parents->first();
            if ($parents->count() !== 1 || $parent->trashed() || $parent->status !== 'active') {
                return null;
            }

            return $this->sameProof(['type' => 'parent', 'id' => $parent->id, 'parent' => $parent], $proof);
        }

        if (($proof['type'] ?? null) === 'parent') {
            return null;
        }

        // A stale student contact must not recover a revoked link or a different account.
        $links = $this->phones->matching(ParentStudentLink::withTrashed(), 'parent_phone', $phone)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->filter(fn ($link) => $this->phones->normalize($link->parent_phone) === $phone);
        foreach ($links as $link) {
            if ($link->trashed() || $link->parent_account_id !== null
                || ! in_array($link->status, ['pending', 'verified', 'active'], true)) {
                return null;
            }
        }

        // New parents need phone ownership, not a school record. Child access remains separate.
        // Keep this proof stable if a school imports their contact while the SMS is in flight.
        if (($proof['type'] ?? null) === 'phone') {
            return $this->sameProof(['type' => 'phone', 'id' => 0], $proof);
        }

        foreach ($links as $link) {
            if (! in_array($link->status, ['verified', 'active'], true)
                || ! in_array($link->relationship, ['parent', 'mother', 'father', 'guardian'], true)) {
                continue;
            }

            $candidate = $this->sameProof(['type' => 'link', 'id' => $link->id], $proof);
            if ($candidate && $this->activeStudent($link->student_id, $lock, $link->tenant_id, $link->school_id)) {
                return $candidate;
            }
        }

        $students = $this->phones->matching(Student::query(), 'parent_phone', $phone)
            ->when($proof !== null, fn ($query) => $query->whereKey($proof['type'] === 'student' ? $proof['id'] : 0))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($students as $student) {
            if ($this->phones->normalize($student->parent_phone) === $phone && $this->activeStudent($student->id, $lock)) {
                return ['type' => 'student', 'id' => $student->id];
            }
        }

        return $this->sameProof(['type' => 'phone', 'id' => 0], $proof);
    }

    private function sameProof(array $candidate, ?array $proof): ?array
    {
        return $proof === null || ($proof['type'] === $candidate['type'] && (int) $proof['id'] === (int) $candidate['id'])
            ? $candidate : null;
    }

    private function activeStudent(int $id, bool $lock, ?int $tenantId = null, ?int $schoolId = null): bool
    {
        $student = Student::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($id);
        if (! $student || ! $student->isAvailableInMobile()
            || ($tenantId !== null && $student->tenant_id !== $tenantId)
            || ($schoolId !== null && $student->school_id !== $schoolId)) {
            return false;
        }

        $school = School::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($student->school_id);
        $tenant = Tenant::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($student->tenant_id);

        return $school && $tenant && $school->tenant_id === $tenant->id
            && $school->status === 'active' && $tenant->status === 'active';
    }
}
