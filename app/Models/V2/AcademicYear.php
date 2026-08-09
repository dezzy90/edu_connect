<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use BelongsToTenant;
    use HasSourceIdentity;
    use SoftDeletes;

    protected $table = 'ec_academic_years';

    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'source_updated_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
