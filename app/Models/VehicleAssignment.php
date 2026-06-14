<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class VehicleAssignment extends Model
{
    protected $table = 'vehicle_assignments';

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    protected $appends = [
        'has_running_activity',
    ];

    /**
     * Relasi ke kendaraan.
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * Relasi ke driver.
     */
    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Relasi ke laporan kerusakan aktif berdasarkan vehicle_id.
     *
     * Digunakan untuk mencegah unassign kendaraan
     * jika masih ada laporan kerusakan yang berjalan.
     */
    public function activeDamageReports()
    {
        return $this->hasMany(DamageReport::class, 'vehicle_id', 'vehicle_id')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $this->finishedStatuses());
            });
    }

    /**
     * Relasi ke booking service aktif berdasarkan vehicle_id.
     *
     * Digunakan untuk mencegah unassign kendaraan
     * jika masih ada maintenance/service yang berjalan.
     */
    public function activeServiceBookings()
    {
        return $this->hasMany(ServiceBooking::class, 'vehicle_id', 'vehicle_id')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $this->finishedStatuses());
            });
    }

    /**
     * Status yang dianggap sudah selesai.
     */
    public function finishedStatuses(): array
    {
        return [
            'completed',
            'finished',
            'selesai',
            'done',
            'closed',
            'rejected',
            'canceled',
            'cancelled',
        ];
    }

    /**
     * Cek apakah kendaraan masih punya aktivitas berjalan.
     *
     * Dibuat lebih aman:
     * - cek class model ada atau tidak
     * - cek tabel ada atau tidak
     * - cek kolom vehicle_id dan status ada atau tidak
     *
     * Jadi controller tidak mudah error saat destroy / unassign.
     */
    public function hasRunningActivity(): bool
    {
        $hasActiveDamageReport = false;
        $hasActiveServiceBooking = false;

        if ($this->canCheckDamageReports()) {
            $hasActiveDamageReport = $this->activeDamageReports()->exists();
        }

        if ($this->canCheckServiceBookings()) {
            $hasActiveServiceBooking = $this->activeServiceBookings()->exists();
        }

        return $hasActiveDamageReport || $hasActiveServiceBooking;
    }

    /**
     * Attribute tambahan untuk JSON response.
     *
     * Output:
     * has_running_activity: true / false
     */
    public function getHasRunningActivityAttribute(): bool
    {
        return $this->hasRunningActivity();
    }

    /**
     * Cek apakah tabel damage_reports aman untuk dicek.
     */
    private function canCheckDamageReports(): bool
    {
        if (!class_exists(DamageReport::class)) {
            return false;
        }

        if (!Schema::hasTable('damage_reports')) {
            return false;
        }

        if (!Schema::hasColumn('damage_reports', 'vehicle_id')) {
            return false;
        }

        if (!Schema::hasColumn('damage_reports', 'status')) {
            return false;
        }

        return true;
    }

    /**
     * Cek apakah tabel service_bookings aman untuk dicek.
     */
    private function canCheckServiceBookings(): bool
    {
        if (!class_exists(ServiceBooking::class)) {
            return false;
        }

        if (!Schema::hasTable('service_bookings')) {
            return false;
        }

        if (!Schema::hasColumn('service_bookings', 'vehicle_id')) {
            return false;
        }

        if (!Schema::hasColumn('service_bookings', 'status')) {
            return false;
        }

        return true;
    }
}