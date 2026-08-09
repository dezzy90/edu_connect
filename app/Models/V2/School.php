<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use BelongsToTenant;
    use HasSourceIdentity;
    use SoftDeletes;

    protected $table = 'ec_schools';

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'mobile_settings' => 'array',
        'source_updated_at' => 'datetime',
    ];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(AcademicClass::class);
    }

    public function biometricDevices(): HasMany
    {
        return $this->hasMany(BiometricDevice::class);
    }

    public function conversationThreads(): HasMany
    {
        return $this->hasMany(ConversationThread::class);
    }
}
