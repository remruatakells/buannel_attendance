<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'device_id',
        'profile_image',
        'name',
    ];

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'user_id');
    }
}
