<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
    ];

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    public function users(): HasMany
    {
        return $this->hasMany(UserModel::class);
    }

    public function timing(): HasOne
    {
        return $this->hasOne(OrganizationTiming::class);
    }

    public function attendancePolicy(): HasOne
    {
        return $this->hasOne(OrganizationAttendancePolicy::class);
    }
}
