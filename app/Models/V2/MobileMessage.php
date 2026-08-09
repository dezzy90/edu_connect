<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobileMessage extends Model
{
    use BelongsToTenant;
    use HasSourceIdentity;
    use SoftDeletes;

    public const STAFF_ONLY_CATEGORIES = [
        'admin_only',
        'internal',
        'staff',
        'staff_notice',
        'staff_only',
        'teacher',
        'teachers',
    ];

    protected $table = 'ec_mobile_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'audience_filters' => 'array',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'source_updated_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MobileMessageRecipient::class, 'message_id');
    }

    public function scopePublishedVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->parentVisible()
            ->where(function (Builder $published): void {
                $published->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(function (Builder $expires): void {
                $expires->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeParentVisible(Builder $query): Builder
    {
        $placeholders = implode(',', array_fill(0, count(self::STAFF_ONLY_CATEGORIES), '?'));

        return $query->where(function (Builder $visible) use ($placeholders): void {
            $visible
                ->whereNull('category')
                ->orWhereRaw("LOWER(category) not in ({$placeholders})", self::STAFF_ONLY_CATEGORIES);
        });
    }

    public static function isStaffOnlyCategory(?string $category): bool
    {
        return in_array(strtolower(trim((string) $category)), self::STAFF_ONLY_CATEGORIES, true);
    }
}
