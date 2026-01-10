<?php

namespace App\Models;

use App\Helpers\MoneyFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Milestone extends Model
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
     * Milestone statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_FUNDED = 'funded';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_RELEASED = 'released';

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'amount',
        'sequence_order',
        'status',
        'verified_at',
        'verified_by',
        'client_notes',
        'company_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'sequence_order' => 'integer',
        'verified_at' => 'datetime',
    ];

    /**
     * Relationship: Milestone belongs to a project
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Relationship: Milestone has one escrow
     */
    public function escrow(): HasOne
    {
        return $this->hasOne(Escrow::class);
    }

    /**
     * Relationship: Milestone has many evidence items
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(MilestoneEvidence::class);
    }

    /**
     * Relationship: Milestone has many disputes
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * Relationship: Milestone verified by user (client)
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Check if milestone is verified
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Check if milestone is funded
     */
    public function isFunded(): bool
    {
        return $this->status === self::STATUS_FUNDED || $this->escrow !== null;
    }

    /**
     * Check if milestone can be released
     */
    public function canBeReleased(): bool
    {
        return $this->status === self::STATUS_APPROVED 
            && $this->escrow !== null 
            && $this->escrow->status === Escrow::STATUS_HELD;
    }

    /**
     * Check if previous milestone is completed
     */
    public function previousMilestoneCompleted(): bool
    {
        if ($this->sequence_order === 1) {
            return true; // First milestone
        }

        $previous = self::where('project_id', $this->project_id)
            ->where('sequence_order', $this->sequence_order - 1)
            ->first();

        return $previous && in_array($previous->status, [self::STATUS_RELEASED, self::STATUS_APPROVED]);
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
        
        return $array;
    }
}
