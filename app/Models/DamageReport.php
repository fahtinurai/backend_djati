<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\TechnicianResponse;
use App\Models\ServiceBooking;
use App\Models\CostEstimate;
use App\Models\TechnicianReview;

class DamageReport extends Model
{
    protected $table = 'damage_reports';

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'description',
        'status', // fallback (jika belum ada response teknisi)
    ];

    // Biar computed muncul saat return JSON
    protected $appends = [
        'computed_status',
        'responsible_technician_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELASI DASAR
    |--------------------------------------------------------------------------
    */

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * History respons teknisi (kronologis)
     * FK: technician_responses.damage_id -> damage_reports.id
     */
    public function technicianResponses()
    {
        return $this->hasMany(TechnicianResponse::class, 'damage_id', 'id')
            ->orderBy('created_at', 'asc');
    }

    /**
     * Respons teknisi terakhir
     * Dipakai untuk status laporan & teknisi penanggung jawab
     */
    public function latestTechnicianResponse()
    {
        return $this->hasOne(TechnicianResponse::class, 'damage_id', 'id')
            ->latestOfMany('updated_at');
    }

    /*
    |--------------------------------------------------------------------------
    | FITUR BARU (TERINTEGRASI DENGAN LAPORAN)
    |--------------------------------------------------------------------------
    */

    /**
     * 1 laporan = 1 booking servis
     */
    public function booking()
    {
        return $this->hasOne(ServiceBooking::class, 'damage_report_id', 'id');
    }

    /**
     * 1 laporan = 1 estimasi biaya (draft/submitted/approved/rejected)
     */
    public function costEstimate()
    {
        return $this->hasOne(CostEstimate::class, 'damage_report_id', 'id');
    }

    /**
     * 1 laporan = 1 review driver ke teknisi (rating+ulasan)
     */
    public function review()
    {
        return $this->hasOne(TechnicianReview::class, 'damage_report_id', 'id');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS / ATTRIBUTES
    |--------------------------------------------------------------------------
    */

    /**
     * Status untuk frontend
     * Prioritas:
     * 1) latestTechnicianResponse.status
     * 2) kolom status di damage_reports
     * default: menunggu
     */
    public function getComputedStatusAttribute(): string
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : null;

        $st = $latest?->status;
        if (is_string($st) && trim($st) !== '') {
            return $st;
        }

        $fallback = $this->status;
        if (is_string($fallback) && trim($fallback) !== '') {
            return $fallback;
        }

        return 'menunggu';
    }

    /**
     * Teknisi penanggung jawab (ambil dari response terakhir)
     */
    public function getResponsibleTechnicianIdAttribute(): ?int
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : null;

        $tid = $latest?->technician_id;
        return $tid ? (int) $tid : null;
    }

    public function isFinished(): bool
    {
        return $this->computed_status === 'selesai';
    }
}
