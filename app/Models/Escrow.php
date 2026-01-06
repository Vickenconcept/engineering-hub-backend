<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Escrow extends Model
{
    use HasFactory;

    /**
     * Escrow statuses
     */
    const STATUS_HELD = 'held';
    const STATUS_RELEASED = 'released';
    const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'milestone_id',
        'amount',
        'payment_reference',
        'payment_provider',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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
}
