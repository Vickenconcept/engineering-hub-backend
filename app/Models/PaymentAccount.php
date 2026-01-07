<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentAccount extends Model
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

        // When setting an account as default, unset other defaults for the same user
        static::updating(function ($model) {
            if ($model->isDirty('is_default') && $model->is_default) {
                static::where('user_id', $model->user_id)
                    ->where('id', '!=', $model->id)
                    ->update(['is_default' => false]);
            }
        });

        static::saving(function ($model) {
            if ($model->is_default) {
                static::where('user_id', $model->user_id)
                    ->where('id', '!=', $model->id ?? null)
                    ->update(['is_default' => false]);
            }
        });
    }

    protected $fillable = [
        'user_id',
        'account_name',
        'account_number',
        'bank_code',
        'bank_name',
        'account_type',
        'currency',
        'is_default',
        'is_verified',
        'recipient_code',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_verified' => 'boolean',
    ];

    /**
     * Relationship: PaymentAccount belongs to a user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the default account for a user
     */
    public static function getDefaultForUser(string $userId): ?self
    {
        return static::where('user_id', $userId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Check if this is the default account
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }
}
