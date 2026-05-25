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

        // Dipakai UI driver saat submit laporan kerusakan
        'damage_type',
        'description',
        'image',

        // Fallback status jika belum ada response teknisi
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Biar computed muncul saat return JSON
    protected $appends = [
        'computed_status',
        'computed_status_label',
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
     * History respons teknisi.
     *
     * FK:
     * technician_responses.damage_id -> damage_reports.id
     *
     * Dipakai UI driver/operator untuk activity logs
     * dan UI teknisi untuk riwayat pekerjaan.
     */
    public function technicianResponses()
    {
        return $this->hasMany(TechnicianResponse::class, 'damage_id', 'id')
            ->orderBy('created_at', 'asc');
    }

    /**
     * Respons teknisi terakhir.
     *
     * Dipakai untuk menentukan status laporan terbaru.
     */
    public function latestTechnicianResponse()
    {
        return $this->hasOne(TechnicianResponse::class, 'damage_id', 'id')
            ->latestOfMany('updated_at');
    }

    /*
    |--------------------------------------------------------------------------
    | FITUR TERINTEGRASI DENGAN LAPORAN
    |--------------------------------------------------------------------------
    */

    /**
     * 1 laporan = 1 booking servis.
     */
    public function booking()
    {
        return $this->hasOne(ServiceBooking::class, 'damage_report_id', 'id');
    }

    /**
     * 1 laporan = 1 estimasi biaya.
     */
    public function costEstimate()
    {
        return $this->hasOne(CostEstimate::class, 'damage_report_id', 'id');
    }

    /**
     * 1 laporan = 1 review driver ke teknisi.
     */
    public function review()
    {
        return $this->hasOne(TechnicianReview::class, 'damage_report_id', 'id');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS / COMPUTED ATTRIBUTES
    |--------------------------------------------------------------------------
    */

    /**
     * Status utama untuk frontend.
     *
     * Prioritas:
     * 1. latestTechnicianResponse.status
     * 2. kolom damage_reports.status
     * 3. default menunggu
     *
     * Nilai backend standar:
     * - menunggu
     * - proses
     * - butuh_followup_admin
     * - fatal
     * - selesai
     */
    public function getComputedStatusAttribute(): string
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : $this->latestTechnicianResponse()->first();

        $latestStatus = $latest?->status;

        if (is_string($latestStatus) && trim($latestStatus) !== '') {
            return $this->normalizeStatus($latestStatus);
        }

        $fallbackStatus = $this->status;

        if (is_string($fallbackStatus) && trim($fallbackStatus) !== '') {
            return $this->normalizeStatus($fallbackStatus);
        }

        return 'menunggu';
    }

    /**
     * Label status untuk UI Flutter.
     *
     * Bisa langsung dipakai frontend kalau ingin lebih konsisten.
     */
    public function getComputedStatusLabelAttribute(): string
    {
        return $this->statusLabel($this->computed_status);
    }

    /**
     * Teknisi penanggung jawab diambil dari response terakhir.
     */
    public function getResponsibleTechnicianIdAttribute(): ?int
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : $this->latestTechnicianResponse()->first();

        $technicianId = $latest?->technician_id;

        return $technicianId ? (int) $technicianId : null;
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
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

    /**
     * Normalisasi status dari berbagai sumber:
     * - Flutter lama
     * - Flutter sekarang
     * - backend lama
     * - backend sekarang
     */
    public function normalizeStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return 'menunggu';
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            'reported',
            'waiting',
            'menunggu' => 'menunggu',

            'ongoing',
            'in_progress',
            'progress',
            'diproses',
            'proses' => 'proses',

            'on_hold',
            'waiting_parts',
            'menunggu_sparepart',
            'butuh_followup',
            'butuh_followup_admin' => 'butuh_followup_admin',

            'finished',
            'completed',
            'complete',
            'selesai' => 'selesai',

            'fatal' => 'fatal',

            default => $status,
        };
    }

    /**
     * Label status untuk tampilan UI.
     */
    public function statusLabel(?string $status): string
    {
        $status = $this->normalizeStatus($status);

        return match ($status) {
            'menunggu' => 'Reported',
            'proses' => 'Ongoing',
            'butuh_followup_admin' => 'On Hold',
            'fatal' => 'Fatal',
            'selesai' => 'Finished',
            default => 'Reported',
        };
    }
}