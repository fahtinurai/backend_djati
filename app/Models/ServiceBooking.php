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
        'technician_id',

        // waktu
        'preferred_at',
        'requested_at',
        'scheduled_at',
        'estimated_finish_at',
        'started_at',
        'completed_at',

        // status & catatan
        'status',
        'priority',
        'note_driver',
        'note_admin',
        'note_technician',

        // KPI teknisi
        'mttr',
        'mtbf',
        'ma',
    ];

    /**
     * =========================
     * Default values
     * =========================
     */
    protected $attributes = [
        'status' => 'requested',
        'priority' => 'medium',
    ];

    /**
     * =========================
     * Casts
     * =========================
     */
    protected $casts = [
        'preferred_at'        => 'datetime',
        'requested_at'        => 'datetime',
        'scheduled_at'        => 'datetime',
        'estimated_finish_at' => 'datetime',
        'started_at'          => 'datetime',
        'completed_at'        => 'datetime',

        'mttr' => 'decimal:2',
        'mtbf' => 'decimal:2',
        'ma'   => 'decimal:1',
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
     * Booking milik satu driver
     */
    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Booking untuk satu kendaraan
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * Teknisi yang ditugaskan admin untuk booking ini
     */
    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Driver dari damage report.
     * Opsional, kalau kamu tetap mau akses driver asli dari laporan.
     */
    public function reportDriver()
    {
        return $this->hasOneThrough(
            User::class,
            DamageReport::class,
            'id',
            'id',
            'damage_report_id',
            'driver_id'
        );
    }

    /**
     * Vehicle dari damage report.
     * Opsional, kalau kamu tetap mau akses kendaraan asli dari laporan.
     */
    public function reportVehicle()
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
     * Helper
     * =========================
     */

    public function isRequested(): bool
    {
        return $this->status === 'requested';
    }

    public function isScheduled(): bool
    {
        return in_array($this->status, ['approved', 'rescheduled'], true);
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCanceled(): bool
    {
        return in_array($this->status, ['canceled', 'cancelled'], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['completed', 'canceled', 'cancelled'], true);
    }
}