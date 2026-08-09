<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Stream extends Model
{
    use BelongsToTenant;
    use HasSourceIdentity;
    use SoftDeletes;

    protected $table = 'ec_streams';

    protected $guarded = ['id'];

    protected $casts = [
        'source_updated_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function educationOption(): BelongsTo
    {
        return $this->belongsTo(EducationOption::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(AcademicClass::class);
    }
}
