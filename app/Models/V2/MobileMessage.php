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

    public const STAFF_ONLY_AUDIENCE_TYPES = [
        'roles',
        'staff',
        'teachers',
    ];

    public const STAFF_ONLY_TEXT_PATTERNS = [
        'internal staff',
        'staff briefing',
        'staff meeting',
        'staff notice',
        'teacher briefing',
        'teacher meeting',
        'teacher notice',
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
        $categoryPlaceholders = implode(',', array_fill(0, count(self::STAFF_ONLY_CATEGORIES), '?'));
        $audiencePlaceholders = implode(',', array_fill(0, count(self::STAFF_ONLY_AUDIENCE_TYPES), '?'));

        return $query
            ->where(function (Builder $visible) use ($categoryPlaceholders): void {
                $visible
                    ->whereNull('category')
                    ->orWhereRaw("LOWER(category) not in ({$categoryPlaceholders})", self::STAFF_ONLY_CATEGORIES);
            })
            ->where(function (Builder $visible) use ($audiencePlaceholders): void {
                $visible
                    ->whereNull('audience_type')
                    ->orWhereRaw("LOWER(audience_type) not in ({$audiencePlaceholders})", self::STAFF_ONLY_AUDIENCE_TYPES);
            })
            ->where(function (Builder $visible): void {
                foreach (self::STAFF_ONLY_TEXT_PATTERNS as $pattern) {
                    $visible
                        ->whereRaw("LOWER(COALESCE(title, '')) not like ?", ["%{$pattern}%"])
                        ->whereRaw("LOWER(COALESCE(body, '')) not like ?", ["%{$pattern}%"]);
                }
            });
    }

    public static function isStaffOnlyCategory(?string $category): bool
    {
        return in_array(strtolower(trim((string) $category)), self::STAFF_ONLY_CATEGORIES, true);
    }

    public static function isStaffOnlyAudienceType(?string $audienceType): bool
    {
        return in_array(strtolower(trim((string) $audienceType)), self::STAFF_ONLY_AUDIENCE_TYPES, true);
    }

    public static function hasStaffOnlyText(?string $title, ?string $body): bool
    {
        $text = strtolower(trim((string) $title . ' ' . (string) $body));

        foreach (self::STAFF_ONLY_TEXT_PATTERNS as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isParentVisible(): bool
    {
        return ! self::isStaffOnlyCategory($this->category)
            && ! self::isStaffOnlyAudienceType($this->audience_type)
            && ! self::hasStaffOnlyText($this->title, $this->body);
    }
}
