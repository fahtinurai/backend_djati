<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $table = 'vehicles';

    /**
     * Field database yang boleh diisi.
     *
     * Catatan:
     * - initial_kpi tetap dipakai sebagai kolom database lama.
     * - Di frontend, initial_kpi ditampilkan sebagai Hour Meter Awal.
     */
    protected $fillable = [
        'equipment_name',
        'brand',
        'model',
        'plate_number',
        'serial_number',
        'initial_kpi',
        'year',

        /**
         * Field tambahan ini boleh dipakai kalau kolomnya sudah ada
         * di database. Kalau belum ada, controller yang sebelumnya
         * saya berikan sudah mengamankan dengan Schema::hasColumn().
         */
        'target_availability',
        'status',
    ];

    protected $casts = [
        'initial_kpi' => 'decimal:2',
        'target_availability' => 'decimal:2',
        'year' => 'integer',
    ];

    /**
     * Tambahan attribute untuk response JSON.
     *
     * Tujuannya agar VehiclesPage.jsx bisa langsung membaca:
     * - initial_hour_meter
     *
     * Walaupun kolom database lama masih:
     * - initial_kpi
     */
    protected $appends = [
        'initial_hour_meter',
        'target_ma',
        'unit_status',
    ];

    /**
     * Mapping initial_kpi ke initial_hour_meter.
     *
     * Di database:
     * - initial_kpi
     *
     * Di frontend:
     * - initial_hour_meter
     */
    public function getInitialHourMeterAttribute()
    {
        return $this->initial_kpi;
    }

    /**
     * Alias target_availability.
     *
     * Kalau kolom target_availability belum ada atau nilainya kosong,
     * default target MA adalah 90%.
     */
    public function getTargetMaAttribute()
    {
        return $this->target_availability ?? 90;
    }

    /**
     * Alias status unit.
     *
     * Kalau kolom status belum ada atau nilainya kosong,
     * default status adalah active.
     */
    public function getUnitStatusAttribute()
    {
        return $this->status ?? 'active';
    }

    /**
     * Relasi ke assignment.
     */
    public function assignment()
    {
        return $this->hasOne(VehicleAssignment::class);
    }

    /**
     * Relasi ke laporan kerusakan.
     */
    public function damageReports()
    {
        return $this->hasMany(DamageReport::class);
    }
}