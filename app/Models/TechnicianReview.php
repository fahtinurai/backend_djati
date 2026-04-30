<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TechnicianReview extends Model
{
    protected $table = 'technician_reviews';

    protected $fillable = [
        'damage_report_id',
        'driver_id',
        'technician_id',
        'rating',
        'review',
        'reviewed_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    // =========================
    // Relations (FIX: jangan select kolom 'name' karena tidak ada di users)
    // =========================
    public function damageReport()
    {
        // optional: pilih field minimal agar payload ringan
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
        // Kalau mau lebih ringan:
        // return $this->belongsTo(DamageReport::class, 'damage_report_id')->select('id', 'vehicle_id', 'driver_id', 'created_at');
    }

    public function driver()
    {
        // ✅ PENTING: batasi field, HILANGKAN 'name'
        return $this->belongsTo(User::class, 'driver_id')
            ->select(['id', 'username']);
    }

    public function technician()
    {
        // ✅ PENTING: batasi field, HILANGKAN 'name'
        return $this->belongsTo(User::class, 'technician_id')
            ->select(['id', 'username']);
    }

    // =========================
    // Query Scopes (biar controller rapi)
    // =========================
    public function scopeForTechnician(Builder $q, int $technicianId): Builder
    {
        return $q->where('technician_id', $technicianId);
    }

    public function scopeForDamageReport(Builder $q, int $damageReportId): Builder
    {
        return $q->where('damage_report_id', $damageReportId);
    }

    public function scopeLatestReviewed(Builder $q): Builder
    {
        return $q->orderByRaw('COALESCE(reviewed_at, created_at) DESC');
    }

    // =========================
    // Helper: cek kepemilikan (buat show)
    // =========================
    public function ownedByDriver(int $driverId): bool
    {
        return (int) $this->driver_id === (int) $driverId;
    }

    public function ownedByTechnician(int $technicianId): bool
    {
        return (int) $this->technician_id === (int) $technicianId;
    }
}
