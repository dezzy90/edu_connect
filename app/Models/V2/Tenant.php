<?php

namespace App\Models\V2;

use App\Models\V2\Concerns\HasSourceIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasSourceIdentity;
    use SoftDeletes;

    protected $table = 'ec_tenants';

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }
}
