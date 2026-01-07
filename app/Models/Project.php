<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
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
     * Project statuses
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_DISPUTED = 'disputed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'client_id',
        'company_id',
        'title',
        'description',
        'location',
        'budget_min',
        'budget_max',
        'status',
    ];

    protected $casts = [
        'budget_min' => 'decimal:2',
        'budget_max' => 'decimal:2',
    ];

    /**
     * Relationship: Project belongs to a client (user)
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * Relationship: Project belongs to a company
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relationship: Project has many milestones
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sequence_order');
    }

    /**
     * Check if all milestones are verified
     */
    public function allMilestonesVerified(): bool
    {
        return $this->milestones()
            ->whereNull('verified_at')
            ->doesntExist();
    }

    /**
     * Relationship: Project has many disputes
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * Check if project has active dispute
     */
    public function hasActiveDispute(): bool
    {
        // Only treat full disputes as blocking, not simple revision requests
        return $this->disputes()
            ->where('status', Dispute::STATUS_OPEN)
            ->where('type', Dispute::TYPE_DISPUTE)
            ->exists();
    }

    /**
     * Get total project value from milestones
     */
    public function getTotalValueAttribute(): float
    {
        return $this->milestones()->sum('amount');
    }
}
