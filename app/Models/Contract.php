<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'status',
        'agreement_number',
        'name',
        'grant_amount',
        'effective_date',
        'expiry_date',
        'template_date',
        'assistance_listings',
        'recipient_name',
        'recipient_street_address',
        'recipient_city_state_zip',
        'recipient_attention',
        'recipient_telephone',
        'recipient_email',
        'company_name',
        'company_division',
        'company_office',
        'company_street_address',
        'company_city_state_zip',
        'company_grant_administrator',
        'company_telephone',
        'company_email',
    ];

    protected $casts = [
        'assistance_listings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function refreshStatusFromExpiry(): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return;
        }

        if (empty($this->expiry_date)) {
            return;
        }

        try {
            if (Carbon::parse($this->expiry_date)->endOfDay()->isPast()) {
                $this->update(['status' => self::STATUS_COMPLETED]);
            }
        } catch (\Throwable) {
            // unparsable date string — leave status unchanged
        }
    }
}
