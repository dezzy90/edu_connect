<?php

namespace App\Services\Auth;

use App\Models\V2\ConversationThread;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One server-owned identity, never a bypass for a school-recorded phone. */
final class ReviewerDemoAccess
{
    public const PHONE = '+12025550102';

    public const SOURCE = 'google-play-review';

    public const SLUG = 'google-play-demo';

    public const SETTING = 'reviewer_demo';

    public const SUBJECT = 'reviewer_parent';

    public function __construct(private MobileOtpPhone $phones) {}

    public function reservedPhone(string $phone): bool
    {
        if ($phone === self::PHONE || $phone === config('reviewer_demo.phone')) {
            return true;
        }

        return $this->phones->matching(ParentAccount::withTrashed(), 'phone', $phone)->get()
            ->contains(fn (ParentAccount $parent) => $this->isDemo($parent));
    }

    public function isDemo(ParentAccount $parent): bool
    {
        return (int) $parent->id === (int) config('reviewer_demo.parent_id')
            || $this->phones->normalize($parent->phone) === self::PHONE
            || array_key_exists(self::SETTING, $parent->settings ?? []);
    }

    public function resolve(string $phone, bool $lock = false): ?array
    {
        if (config('reviewer_demo.enabled') !== true || $phone !== self::PHONE
            || config('reviewer_demo.phone') !== self::PHONE
            || ! $this->validPinHash((string) config('reviewer_demo.pin_hash'))
            || ! Str::isUuid((string) config('reviewer_demo.marker'))) {
            return null;
        }

        $parents = $this->phones->matching(ParentAccount::withTrashed(), 'phone', $phone)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $parent = $parents->first();
        $tenant = Tenant::withTrashed()->when($lock, fn ($query) => $query->lockForUpdate())
            ->find((int) config('reviewer_demo.tenant_id'));
        if ($parents->count() !== 1 || ! $parent || ! $tenant
            || (int) $parent->id !== (int) config('reviewer_demo.parent_id')
            || ! $this->isolated($parent, $tenant, (string) config('reviewer_demo.marker'), $lock)) {
            return null;
        }

        return ['type' => self::SUBJECT, 'id' => $parent->id, 'parent' => $parent];
    }

    public function validPinHash(string $hash): bool
    {
        return in_array(password_get_info($hash)['algoName'], ['bcrypt', 'argon2id'], true);
    }

    /** Also used by the provisioner before enabling access; no settings are repaired here. */
    public function isolated(ParentAccount $parent, Tenant $tenant, string $marker, bool $lock = false): bool
    {
        if (! Str::isUuid($marker) || $parent->trashed() || $parent->status !== 'active'
            || $parent->phone !== self::PHONE || $parent->email !== null || $parent->password_hash !== null
            || $parent->otp_secret !== null || $tenant->trashed() || $tenant->status !== 'active'
            || $tenant->slug !== self::SLUG || $tenant->source_system !== self::SOURCE
            || $tenant->source_id !== $marker
            || ($tenant->settings[self::SETTING] ?? null) !== ['marker' => $marker, 'synthetic' => true]
            || ($parent->settings[self::SETTING] ?? null) !== ['marker' => $marker, 'tenant_id' => (int) $tenant->id]) {
            return false;
        }

        $rows = fn (string $table) => DB::table($table)->when($lock, fn ($query) => $query->lockForUpdate());
        if ($rows('ec_integration_connections')->where('tenant_id', $tenant->id)->exists()
            || $this->phones->matching(\App\Models\AdminUser::query(), 'phone', self::PHONE)->exists()) {
            return false;
        }

        $schools = $rows('ec_schools')->where('tenant_id', $tenant->id)->get()->keyBy('id');
        if ($schools->isEmpty()) {
            return false;
        }
        foreach ($schools as $school) {
            $settings = json_decode($school->settings ?? '{}', true);
            if ($school->deleted_at || $school->status !== 'active' || $school->source_system !== self::SOURCE
                || ! str_starts_with((string) $school->source_id, $marker.':')
                || ($settings[self::SETTING] ?? null) !== $marker) {
                return false;
            }
        }

        $students = $rows('ec_students')->where(fn ($query) => $query->where('tenant_id', $tenant->id)
            ->orWhereIn('school_id', $schools->keys()))->get()->keyBy('id');
        if ($students->isEmpty()) {
            return false;
        }
        foreach ($students as $student) {
            if ((int) $student->tenant_id !== (int) $tenant->id || ! $schools->has($student->school_id)
                || $student->deleted_at || $student->source_system !== self::SOURCE
                || ! str_starts_with((string) $student->source_id, $marker.':')
                || $student->device_sync_enabled || $student->biometric_identifier !== null
                || $student->parent_phone !== self::PHONE || $student->parent_email !== null
                || $student->emergency_contact_phone !== null) {
                return false;
            }
            if ($student->class_id !== null) {
                $class = $rows('ec_classes')->where('id', $student->class_id)->first();
                if (! $class || $class->deleted_at || $class->source_system !== self::SOURCE
                    || ! str_starts_with((string) $class->source_id, $marker.':')
                    || (int) $class->tenant_id !== (int) $tenant->id || (int) $class->school_id !== (int) $student->school_id) {
                    return false;
                }
            }
        }

        $links = $this->phones->matching(ParentStudentLink::withTrashed(), 'parent_phone', self::PHONE)
            ->orWhere('parent_account_id', $parent->id)->orWhere('tenant_id', $tenant->id)
            ->orWhereIn('student_id', $students->keys())
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $active = false;
        foreach ($links as $link) {
            $student = $students->get($link->student_id);
            if (! $student || $link->trashed() || (int) $link->tenant_id !== (int) $tenant->id
                || (int) $link->school_id !== (int) $student->school_id || $link->parent_phone !== self::PHONE
                || $link->source_system !== self::SOURCE
                || ($link->parent_account_id !== null && (int) $link->parent_account_id !== (int) $parent->id)
                || ! in_array($link->status, ['active', 'verified', 'pending'], true)) {
                return false;
            }
            $active = $active || ($link->status === 'active' && (int) $link->parent_account_id === (int) $parent->id);
        }
        if (! $active) {
            return false;
        }

        // Fail closed even for membership corruption that bypassed Eloquent model events.
        $threads = $rows('ec_conversation_threads')->where('tenant_id', $tenant->id)
            ->orWhereIn('school_id', $schools->keys())->orWhereIn('student_id', $students->keys())
            ->orWhereIn('id', DB::table('ec_conversation_participants')->select('thread_id')
                ->where('participant_type', 'parent_account')->where('participant_id', $parent->id))->get();
        foreach ($threads as $thread) {
            if ((int) $thread->tenant_id !== (int) $tenant->id || ! $schools->has($thread->school_id)
                || ($thread->student_id !== null && ! $students->has($thread->student_id))) {
                return false;
            }
        }
        if ($rows('ec_conversation_participants')->whereIn('thread_id', $threads->pluck('id'))
            ->where(fn ($query) => $query->where('participant_type', 'admin_user')
                ->orWhere(fn ($query) => $query->where('participant_type', 'parent_account')
                    ->where(fn ($query) => $query->whereNull('participant_id')->orWhere('participant_id', '!=', $parent->id))))->exists()
            || $rows('ec_mobile_notifications')->where('parent_account_id', $parent->id)->where('tenant_id', '!=', $tenant->id)->exists()
            || $rows('ec_mobile_message_recipients')->join('ec_mobile_messages', 'ec_mobile_messages.id', '=', 'message_id')
                ->where('parent_account_id', $parent->id)->where('ec_mobile_messages.tenant_id', '!=', $tenant->id)->exists()) {
            return false;
        }

        return true;
    }

    public function assertParent(ParentAccount $parent): void
    {
        if ($this->isDemo($parent)) {
            $proof = $this->resolve($this->phones->normalize($parent->phone) ?? '');
            abort_unless($proof && (int) $proof['id'] === (int) $parent->id, 403, 'This account is unavailable.');
        }
    }

    public function assertStudent(ParentAccount $parent, Student $student): void
    {
        $demo = $this->isDemo($parent);
        $synthetic = $student->source_system === self::SOURCE
            || (int) $student->tenant_id === (int) config('reviewer_demo.tenant_id')
            || Tenant::query()->whereKey($student->tenant_id)->where('source_system', self::SOURCE)->exists();
        if ($demo || $synthetic) {
            $this->assertParent($parent);
            abort_unless($demo && $synthetic && (int) $student->tenant_id === (int) config('reviewer_demo.tenant_id')
                && $student->source_system === self::SOURCE, 403, 'This child is not available.');
        }
    }

    public function assertLink(ParentStudentLink $link): void
    {
        if ($link->parent_account_id === null) {
            return;
        }
        $parent = ParentAccount::withTrashed()->find($link->parent_account_id);
        $student = Student::withTrashed()->find($link->student_id);
        if ($parent && $student) {
            $this->assertStudent($parent, $student);
            if ($this->isDemo($parent)) {
                abort_unless((int) $link->tenant_id === (int) $student->tenant_id
                    && (int) $link->school_id === (int) $student->school_id
                    && $link->parent_phone === self::PHONE && $link->source_system === self::SOURCE, 403);
            }
        }
    }

    public function assertThread(ParentAccount $parent, ConversationThread $thread): void
    {
        $synthetic = (int) $thread->tenant_id === (int) config('reviewer_demo.tenant_id')
            || Tenant::query()->whereKey($thread->tenant_id)->where('source_system', self::SOURCE)->exists();
        if ($this->isDemo($parent) || $synthetic) {
            $this->assertParent($parent);
            abort_unless($this->isDemo($parent) && $synthetic
                && (int) $thread->tenant_id === (int) config('reviewer_demo.tenant_id'), 403);
        }
    }

    public function protectIdentity(ParentAccount $parent): void
    {
        if (! $parent->exists) {
            return;
        }
        $old = $parent->getOriginal('settings')[self::SETTING] ?? null;
        abort_unless($old === ($parent->settings[self::SETTING] ?? null), 403, 'Account identity is managed by the server.');
        if ($this->isDemo($parent) || $old !== null) {
            foreach (['phone', 'email', 'password_hash', 'otp_secret'] as $field) {
                abort_if($parent->isDirty($field), 403, 'Account identity is managed by the server.');
            }
        }
    }
}
