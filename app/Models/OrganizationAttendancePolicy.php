<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAttendancePolicy extends Model
{
    public const DEFAULT_ALLOW_HALF_DAY = true;
    public const DEFAULT_ALLOW_LEAVE = true;
    public const DEFAULT_ANNUAL_LEAVE_LIMIT = 0;

    protected $fillable = [
        'organization_id',
        'allow_half_day',
        'allow_leave',
        'annual_leave_limit',
    ];

    protected function casts(): array
    {
        return [
            'allow_half_day' => 'boolean',
            'allow_leave' => 'boolean',
            'annual_leave_limit' => 'integer',
        ];
    }

    /**
     * @return array<string, bool|int>
     */
    public static function defaults(): array
    {
        return [
            'allow_half_day' => self::DEFAULT_ALLOW_HALF_DAY,
            'allow_leave' => self::DEFAULT_ALLOW_LEAVE,
            'annual_leave_limit' => self::DEFAULT_ANNUAL_LEAVE_LIMIT,
        ];
    }

    public static function defaultPolicy(): self
    {
        return new self(self::defaults());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
