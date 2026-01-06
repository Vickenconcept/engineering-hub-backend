<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneEvidence extends Model
{
    use HasFactory;

    /**
     * Evidence types
     */
    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_TEXT = 'text';

    protected $table = 'milestone_evidence';

    protected $fillable = [
        'milestone_id',
        'type',
        'file_path',
        'description',
        'uploaded_by',
    ];

    /**
     * Relationship: Evidence belongs to a milestone
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * Relationship: Evidence uploaded by a user
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Check if evidence is an image
     */
    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    /**
     * Check if evidence is a video
     */
    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }
}
