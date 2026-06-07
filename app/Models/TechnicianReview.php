<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\DamageReport;
use App\Models\User;

class TechnicianReview extends Model
{
    protected $table = 'technician_reviews';

    /*
    |--------------------------------------------------------------------------
    | MASS ASSIGNMENT
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'damage_report_id',
        'driver_id',
        'technician_id',
        'rating',
        'review',
        'reviewed_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */
    protected $casts = [
        'rating' => 'integer',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS (FIXED + SAFE)
    |--------------------------------------------------------------------------
    */

    /**
     * Relasi ke DamageReport
     * Dipakai untuk tracking review per laporan
     */
    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
    }

    /**
     * Driver yang memberikan review
     * FIX: hanya ambil field yang valid (tanpa name)
     */
    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id')
            ->select(['id', 'username']);
    }

    /**
     * Teknisi yang direview
     * FIX: hanya ambil field yang valid (tanpa name)
     */
    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id')
            ->select(['id', 'username']);
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES (KEEP CLEAN CONTROLLER)
    |--------------------------------------------------------------------------
    */

    /**
     * Filter review berdasarkan teknisi
     */
    public function scopeForTechnician(Builder $q, int $technicianId): Builder
    {
        return $q->where('technician_id', $technicianId);
    }

    /**
     * Filter review berdasarkan damage report
     */
    public function scopeForDamageReport(Builder $q, int $damageReportId): Builder
    {
        return $q->where('damage_report_id', $damageReportId);
    }

    /**
     * Urutkan review terbaru
     */
    public function scopeLatestReviewed(Builder $q): Builder
    {
        return $q->orderByRaw('COALESCE(reviewed_at, created_at) DESC');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS (SAFE CHECK OWNERSHIP)
    |--------------------------------------------------------------------------
    */

    /**
     * Cek apakah review milik driver tertentu
     */
    public function ownedByDriver(int $driverId): bool
    {
        return (int) $this->driver_id === (int) $driverId;
    }

    /**
     * Cek apakah review milik teknisi tertentu
     */
    public function ownedByTechnician(int $technicianId): bool
    {
        return (int) $this->technician_id === (int) $technicianId;
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSOR (OPTIONAL - CONSISTENT UI)
    |--------------------------------------------------------------------------
    */

    /**
     * Label rating sederhana untuk UI (optional)
     */
    public function getRatingLabelAttribute(): string
    {
        return match (true) {
            $this->rating >= 5 => 'Excellent',
            $this->rating >= 4 => 'Good',
            $this->rating >= 3 => 'Average',
            $this->rating >= 2 => 'Poor',
            default => 'Very Poor',
        };
    }
}