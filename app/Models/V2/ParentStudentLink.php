<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\BelongsToTenant;
use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParentStudentLink extends Model
{
    use BelongsToTenant;
    use HasSourceIdentity;
    use SoftDeletes;

    protected $table = 'ec_parent_student_links';

    protected $guarded = ['id'];

    protected $casts = [
        'communication_preferences' => 'array',
        'is_primary_contact' => 'boolean',
        'can_pick_up' => 'boolean',
        'emergency_contact' => 'boolean',
        'requested_at' => 'datetime',
        'verified_at' => 'datetime',
        'linked_at' => 'datetime',
        'source_updated_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
