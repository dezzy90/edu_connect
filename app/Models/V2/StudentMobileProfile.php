<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMobileProfile extends Model
{
    use BelongsToTenant;
    use HasSourceIdentity;

    protected $table = 'ec_student_mobile_profiles';

    protected $guarded = ['id'];

    protected $casts = [
        'profile' => 'array',
        'source_updated_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
