<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\DamageReport;
use App\Models\User;
use App\Models\Vehicle;

class ServiceBooking extends Model
{
    protected $table = 'service_bookings';

    /*
    |----------------------------------------------------------------------
    | MASS ASSIGNMENT
    |----------------------------------------------------------------------
    */
    protected $fillable = [
        'damage_report_id',
        'driver_id',
        'vehicle_id',
        'technician_id',

        'preferred_at',
        'requested_at',
        'scheduled_at',
        'estimated_finish_at',
        'started_at',
        'completed_at',

        'status',
        'priority',

        'note_driver',
        'note_admin',
        'note_technician',

        'mttr',
        'mtbf',
        'ma',
    ];

    /*
    |----------------------------------------------------------------------
    | DEFAULT ATTRIBUTES
    |----------------------------------------------------------------------
    */
    protected $attributes = [
        'status' => 'requested',
        'priority' => 'medium',
    ];

    /*
    |----------------------------------------------------------------------
    | CASTS
    |----------------------------------------------------------------------
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

    /*
    |----------------------------------------------------------------------
    | RELATIONS
    |----------------------------------------------------------------------
    */

    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /*
    |----------------------------------------------------------------------
    | SAFE ACCESSORS (FIX UTAMA)
    |----------------------------------------------------------------------
    */

    /**
     * Driver dari damage report (SAFE VERSION)
     */
    public function getReportDriverAttribute()
    {
        return $this->damageReport?->driver;
    }

    /**
     * Vehicle dari damage report (SAFE VERSION)
     */
    public function getReportVehicleAttribute()
    {
        return $this->damageReport?->vehicle;
    }

    /*
    |----------------------------------------------------------------------
    | HELPERS STATUS FLOW (TIDAK DIUBAH LOGIKA)
    |----------------------------------------------------------------------
    */

    public function isRequested(): bool
    {
        return $this->status === 'requested';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRescheduled(): bool
    {
        return $this->status === 'rescheduled';
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

    /*
    |----------------------------------------------------------------------
    | BUSINESS FLOW HELPERS (TAMBAHAN AMAN)
    |----------------------------------------------------------------------
    */

    /**
     * Status aktif untuk dashboard teknisi/admin
     */
    public function isActive(): bool
    {
        return in_array($this->status, [
            'approved',
            'rescheduled',
            'in_progress'
        ], true);
    }

    /**
     * Apakah booking bisa di-assign ulang
     */
    public function isAssignable(): bool
    {
        return in_array($this->status, [
            'requested',
            'approved',
            'rescheduled'
        ], true);
    }

    /**
     * Apakah job sudah selesai lifecycle
     */
    public function isClosed(): bool
    {
        return in_array($this->status, [
            'completed',
            'canceled',
            'cancelled'
        ], true);
    }

    /*
    |----------------------------------------------------------------------
    | SCHEDULING HELPER (INI PENTING BUAT DROPDOWN NANTI)
    |----------------------------------------------------------------------
    */

    /**
     * Apakah booking punya jadwal valid
     */
    public function hasSchedule(): bool
    {
        return !is_null($this->scheduled_at);
    }

    /**
     * Apakah masih bisa dipindah jadwal
     */
    public function canReschedule(): bool
    {
        return !$this->isClosed() && $this->status !== 'in_progress';
    }
}