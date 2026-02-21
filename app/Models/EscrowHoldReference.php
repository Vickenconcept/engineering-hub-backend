<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Central reference for milestone escrow payments.
 * One hold_ref links: client (payer), company (payee when released), project, milestone,
 * and Paystack refs for charge (hold) and transfer (release).
 * Use this ID to look up who paid, who gets paid, and related entities.
 */
class EscrowHoldReference extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    const STATUS_HELD = 'held';
    const STATUS_RELEASED = 'released';
    const STATUS_REFUNDED = 'refunded';

    const PREFIX = 'EHR-';

    protected $fillable = [
        'hold_ref',
        'escrow_id',
        'project_id',
        'milestone_id',
        'client_id',
        'company_id',
        'paystack_charge_reference',
        'paystack_transfer_reference',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
            if (empty($model->hold_ref)) {
                $model->hold_ref = self::generateUniqueHoldRef();
            }
        });
    }

    /**
     * Generate a unique hold_ref (EHR- + 12 alphanumeric).
     */
    public static function generateUniqueHoldRef(): string
    {
        $maxAttempts = 20;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $ref = self::PREFIX . strtoupper(Str::random(12));
            if (!self::where('hold_ref', $ref)->exists()) {
                return $ref;
            }
        }
        return self::PREFIX . strtoupper(Str::random(12)) . substr((string) time(), -4);
    }

    /**
     * Create a hold reference for an escrow (call after Escrow::create).
     */
    public static function createForEscrow(Escrow $escrow, string $paystackChargeReference): self
    {
        $escrow->loadMissing(['milestone.project']);
        $milestone = $escrow->milestone;
        $project = $milestone->project;

        return self::create([
            'escrow_id' => $escrow->id,
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
            'client_id' => $project->client_id,
            'company_id' => $project->company_id,
            'paystack_charge_reference' => $paystackChargeReference,
            'status' => self::STATUS_HELD,
        ]);
    }

    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }
}
