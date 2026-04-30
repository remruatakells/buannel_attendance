<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'user_id',
        'attendance_date',
        'check_in',
        'check_out',
        'status',
    ];

    protected function checkIn(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->formatTimeForApi($value),
            set: fn (?string $value) => $this->formatTimeForStorage($value),
        );
    }

    protected function checkOut(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->formatTimeForApi($value),
            set: fn (?string $value) => $this->formatTimeForStorage($value),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    private function formatTimeForApi(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->format('h:i:s A') : null;
    }

    private function formatTimeForStorage(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->format('H:i:s') : null;
    }
}
