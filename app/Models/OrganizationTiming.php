<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationTiming extends Model
{
    public const DEFAULT_CHECK_IN_START = '09:00:00';
    public const DEFAULT_CHECK_IN_END = '10:00:00';
    public const DEFAULT_LATE_AFTER = '09:30:00';
    public const DEFAULT_CHECK_OUT_START = '16:00:00';

    protected $fillable = [
        'organization_id',
        'check_in_start',
        'check_in_end',
        'late_after',
        'check_out_start',
    ];

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'check_in_start' => self::DEFAULT_CHECK_IN_START,
            'check_in_end' => self::DEFAULT_CHECK_IN_END,
            'late_after' => self::DEFAULT_LATE_AFTER,
            'check_out_start' => self::DEFAULT_CHECK_OUT_START,
        ];
    }

    public static function defaultTiming(): self
    {
        return new self(self::defaults());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
