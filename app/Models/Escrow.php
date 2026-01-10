<?php

namespace App\Models;

use App\Helpers\MoneyFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Escrow extends Model
{
    use HasFactory;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Escrow statuses
     */
    const STATUS_HELD = 'held';
    const STATUS_RELEASED = 'released';
    const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'milestone_id',
        'amount',
        'platform_fee',
        'net_amount',
        'platform_fee_percentage',
        'payment_reference',
        'payment_provider',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'platform_fee_percentage' => 'decimal:2',
    ];

    /**
     * Relationship: Escrow belongs to a milestone
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * Check if escrow is held
     */
    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    /**
     * Check if escrow is released
     */
    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    /**
     * Get the formatted amount attribute
     */
    public function getFormattedAmountAttribute(): ?string
    {
        return MoneyFormatter::format($this->amount);
    }

    /**
     * Get the formatted platform fee attribute
     */
    public function getFormattedPlatformFeeAttribute(): ?string
    {
        return MoneyFormatter::format($this->platform_fee);
    }

    /**
     * Get the formatted net amount attribute
     */
    public function getFormattedNetAmountAttribute(): ?string
    {
        return MoneyFormatter::format($this->net_amount);
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Format money fields
        if (isset($array['amount'])) {
            $array['amount'] = MoneyFormatter::format($this->amount);
        }
        if (isset($array['platform_fee'])) {
            $array['platform_fee'] = MoneyFormatter::format($this->platform_fee);
        }
        if (isset($array['net_amount'])) {
            $array['net_amount'] = MoneyFormatter::format($this->net_amount);
        }
        
        return $array;
    }
}
