<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DocumentUpdateRequest extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    const STATUS_PENDING = 'pending';
    const STATUS_GRANTED = 'granted';
    const STATUS_DENIED = 'denied';

    const TYPE_PREVIEW_IMAGE = 'preview_image';
    const TYPE_DRAWING_ARCHITECTURAL = 'drawing_architectural';
    const TYPE_DRAWING_STRUCTURAL = 'drawing_structural';
    const TYPE_DRAWING_MECHANICAL = 'drawing_mechanical';
    const TYPE_DRAWING_TECHNICAL = 'drawing_technical';
    const TYPE_EXTRA_DOCUMENT = 'extra_document';

    protected $fillable = [
        'project_id',
        'document_type',
        'extra_document_id',
        'requested_by',
        'status',
        'granted_by',
        'granted_at',
        'denied_at',
        'reason',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'denied_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function extraDocument(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'extra_document_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isGranted(): bool
    {
        return $this->status === self::STATUS_GRANTED;
    }

    public function isDenied(): bool
    {
        return $this->status === self::STATUS_DENIED;
    }
}
