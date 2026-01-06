<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

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
        'portfolio_links',
        'specialization',
        'verified_at',
        'status',
    ];

    protected $casts = [
        'license_documents' => 'array',
        'portfolio_links' => 'array',
        'specialization' => 'array',
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
