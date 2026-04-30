<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceBooking extends Model
{
    protected $table = 'service_bookings';

    /**
     * =========================
     * Mass assignment
     * =========================
     */
    protected $fillable = [
        'damage_report_id',
        'driver_id',
        'vehicle_id',

        // waktu
        'preferred_at',          // ← preferensi waktu dari driver
        'requested_at',          // ← waktu driver mengajukan
        'scheduled_at',          // ← jadwal final admin
        'estimated_finish_at',   // ← estimasi selesai

        // status & catatan
        'status',
        'note_driver',
        'note_admin',
    ];

    /**
     * =========================
     * Default values
     * =========================
     */
    protected $attributes = [
        'status' => 'requested',
    ];

    /**
     * =========================
     * Casts (penting untuk sinkron waktu)
     * =========================
     */
    protected $casts = [
        'preferred_at'        => 'datetime',
        'requested_at'        => 'datetime',
        'scheduled_at'        => 'datetime',
        'estimated_finish_at' => 'datetime',
    ];

    /**
     * =========================
     * Relations
     * =========================
     */

    /**
     * Booking milik satu damage report
     */
    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
    }

    /**
     * Shortcut ke driver via damage report
     * (tanpa perlu kolom driver_id di service_bookings)
     */
    public function driver()
    {
        return $this->hasOneThrough(
            User::class,
            DamageReport::class,
            'id',           // PK di damage_reports
            'id',           // PK di users
            'damage_report_id', // FK di service_bookings
            'driver_id'     // FK di damage_reports
        );
    }

    /**
     * Shortcut ke vehicle via damage report
     */
    public function vehicle()
    {
        return $this->hasOneThrough(
            Vehicle::class,
            DamageReport::class,
            'id',
            'id',
            'damage_report_id',
            'vehicle_id'
        );
    }

    /**
     * =========================
     * Helper (opsional tapi sangat berguna)
     * =========================
     */

    /**
     * Apakah booking masih tahap pengajuan driver
     */
    public function isRequested(): bool
    {
        return $this->status === 'requested';
    }

    /**
     * Apakah booking sudah dijadwalkan admin
     */
    public function isScheduled(): bool
    {
        return in_array($this->status, ['approved', 'rescheduled'], true);
    }

    /**
     * Apakah booking sudah dibatalkan
     */
    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }
}
