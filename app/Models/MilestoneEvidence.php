<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    protected $fillable = [
        'milestone_id',
        'type',
        'file_path', // Keep for backward compatibility
        'url', // Cloudinary URL
        'public_id', // Cloudinary public ID for deletion
        'thumbnail_url', // For videos
        'description',
        'uploaded_by',
    ];

    /**
     * Get the file URL (prefer Cloudinary URL over file_path)
     */
    public function getFileUrlAttribute(): ?string
    {
        return $this->url ?? ($this->file_path ? Storage::url($this->file_path) : null);
    }

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
