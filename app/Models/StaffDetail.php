<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class StaffDetail extends Model
{
    use HasFactory;

    protected $table = 'staff_details';

    protected $fillable = [
        'user_id',
        'salary',
        'salary_currency',
        'salary_frequency',
        'join_date',
        'position',
        'department',
        'notes',
    ];

    protected $hidden = [
        'salary',
    ];

    protected $casts = [
        'join_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    public function setSalaryAttribute($value): void
    {
        $this->attributes['salary'] = $value !== null ? Crypt::encryptString((string) $value) : null;
    }

    public function getSalaryAttribute($value)
    {
        if ($value === null) {
            return null;
        }

        try {
            return (float) Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
