<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
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
     * Company statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id',
        'company_name',
        'registration_number',
        'license_documents',
        'cac_certificate',
        'memart',
        'application_for_registration',
        'portfolio_links',
        'specialization',
        'consultation_fee',
        'verified_at',
        'status',
        'suspension_reason',
    ];

    protected $casts = [
        'license_documents' => 'array',
        'portfolio_links' => 'array',
        'specialization' => 'array',
        'consultation_fee' => 'decimal:2',
        'verified_at' => 'datetime',
    ];

    /**
     * Relationship: Company belongs to a user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship: Company has many consultations
     */
    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    /**
     * Relationship: Company has many projects
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Check if company is verified
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if company is approved
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
