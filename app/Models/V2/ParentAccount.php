<?php

namespace App\Models\V2;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class ParentAccount extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'ec_parent_accounts';

    protected $guarded = ['id'];

    protected $authPasswordName = 'password_hash';

    protected $rememberTokenName = '';

    protected $hidden = [
        'password_hash',
        'otp_secret',
    ];

    protected $casts = [
        'settings' => 'array',
        'last_login_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    public function studentLinks(): HasMany
    {
        return $this->hasMany(ParentStudentLink::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MobileNotification::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function messageRecipients(): HasMany
    {
        return $this->hasMany(MobileMessageRecipient::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(MobilePushToken::class);
    }

    public function realtimeSubscriptions(): HasMany
    {
        return $this->hasMany(RealtimeSubscription::class);
    }

    public function conversationParticipants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class, 'participant_id')
            ->where('participant_type', ConversationParticipant::TYPE_PARENT);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
