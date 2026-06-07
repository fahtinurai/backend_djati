<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DamageReport extends Model
{
    protected $table = 'damage_reports';

    /*
    |--------------------------------------------------
    | MASS ASSIGNMENT
    |--------------------------------------------------
    */
    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'damage_type',
        'description',
        'image',
        'status',
    ];

    /*
    |--------------------------------------------------
    | CASTING
    |--------------------------------------------------
    */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------
    | APPENDS
    |--------------------------------------------------
    */
    protected $appends = [
        'image_url',
        'computed_status',
        'computed_status_label',
        'responsible_technician_id',
    ];

    /*
    |--------------------------------------------------
    | IMAGE FIX (AMAN + FLEXIBLE)
    |--------------------------------------------------
    */
    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        // sudah full URL (S3 / CDN / external)
        if (str_starts_with($this->image, 'http')) {
            return $this->image;
        }

        // cek file exists di storage (lebih aman)
        if (Storage::disk('public')->exists($this->image)) {
            return asset('storage/' . $this->image);
        }

        // fallback tetap return URL meskipun file tidak ketemu
        return asset('storage/' . $this->image);
    }

    /*
    |--------------------------------------------------
    | RELATIONS (TIDAK DIUBAH)
    |--------------------------------------------------
    */

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function technicianResponses()
    {
        return $this->hasMany(TechnicianResponse::class, 'damage_id', 'id')
            ->orderBy('created_at', 'asc');
    }

    public function latestTechnicianResponse()
    {
        return $this->hasOne(TechnicianResponse::class, 'damage_id', 'id')
            ->latestOfMany('updated_at');
    }

    public function booking()
    {
        return $this->hasOne(ServiceBooking::class, 'damage_report_id', 'id');
    }

    public function costEstimate()
    {
        return $this->hasOne(CostEstimate::class, 'damage_report_id', 'id');
    }

    public function review()
    {
        return $this->hasOne(TechnicianReview::class, 'damage_report_id', 'id');
    }

    /*
    |--------------------------------------------------
    | COMPUTED STATUS (FIX NULL SAFE)
    |--------------------------------------------------
    */

    public function getComputedStatusAttribute(): string
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : $this->latestTechnicianResponse()->first();

        $status = $latest?->status
            ?? $this->status
            ?? 'menunggu';

        return $this->normalizeStatus($status);
    }

    public function getComputedStatusLabelAttribute(): string
    {
        return match ($this->computed_status) {
            'menunggu' => 'Reported',
            'proses' => 'In Progress',
            'butuh_followup_admin' => 'On Hold',
            'fatal' => 'Fatal',
            'selesai' => 'Finished',
            default => 'Reported',
        };
    }

    public function getResponsibleTechnicianIdAttribute(): ?int
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : $this->latestTechnicianResponse()->first();

        return $latest?->technician_id ? (int) $latest->technician_id : null;
    }

    /*
    |--------------------------------------------------
    | HELPERS
    |--------------------------------------------------
    */

    public function isReported(): bool
    {
        return $this->computed_status === 'menunggu';
    }

    public function isInProgress(): bool
    {
        return $this->computed_status === 'proses';
    }

    public function isOnHold(): bool
    {
        return $this->computed_status === 'butuh_followup_admin';
    }

    public function isFatal(): bool
    {
        return $this->computed_status === 'fatal';
    }

    public function isFinished(): bool
    {
        return $this->computed_status === 'selesai';
    }

    /*
    |--------------------------------------------------
    | NORMALIZER (ROBUST + FUTURE SAFE)
    |--------------------------------------------------
    */

    public function normalizeStatus(?string $status): string
    {
        if (empty($status)) {
            return 'menunggu';
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {

            // waiting state
            'reported',
            'waiting',
            'menunggu' => 'menunggu',

            // in progress
            'ongoing',
            'in_progress',
            'diproses',
            'proses' => 'proses',

            // hold state
            'on_hold',
            'waiting_parts',
            'butuh_followup',
            'butuh_followup_admin' => 'butuh_followup_admin',

            // finished
            'finished',
            'completed',
            'selesai' => 'selesai',

            // critical
            'fatal' => 'fatal',

            default => 'menunggu',
        };
    }
}