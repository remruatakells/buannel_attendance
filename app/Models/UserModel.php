<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\StaffDetail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserModel extends Model
{
    use HasFactory;

    protected $table = 'users';

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected $fillable = [
        'employee_id',
        'first_name',
        'last_name',
        'phone_no',
        'password',
        'is_admin',
        'device_id',
        'profile_image',
        'organization_id',
        'name',
        'admin_access_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'admin_access_token',
    ];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'user_id');
    }

    public function staffDetail(): HasOne
    {
        return $this->hasOne(StaffDetail::class, 'user_id');
    }

    public function scopeVisibleTo(Builder $query, ?self $viewer): Builder
    {
        if (!$viewer) {
            return $query;
        }

        return $query->where('organization_id', $viewer->organization_id);
    }
}
