<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Fields yang boleh diisi
     */
    protected $fillable = [
        'name',
        'username',
        'login_key',
        'role',
        'is_active',
        'email',
        'password',
    ];

    /**
     * Fields yang disembunyikan saat JSON output
     */
    protected $hidden = [
        'login_key',
        'password',
        'remember_token',
    ];

    /**
     * Cast otomatis
     *
     * Catatan:
     * is_active wajib dibuat boolean supaya frontend membaca status user dengan stabil.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active'         => 'boolean',
    ];

    /**
     * Relasi ke kendaraan yang di-assign ke driver
     */
    public function vehicleAssignments()
    {
        return $this->hasMany(VehicleAssignment::class, 'driver_id');
    }

    /**
     * Relasi kendaraan aktif / assignment terbaru milik driver
     *
     * Dipakai jika sistem 1 driver = 1 kendaraan aktif.
     */
    public function latestVehicleAssignment()
    {
        return $this->hasOne(VehicleAssignment::class, 'driver_id')
            ->latestOfMany('assigned_at');
    }

    /**
     * Relasi laporan kerusakan milik driver
     */
    public function damageReports()
    {
        return $this->hasMany(DamageReport::class, 'driver_id');
    }

    /**
     * Relasi booking service milik driver
     */
    public function driverServiceBookings()
    {
        return $this->hasMany(ServiceBooking::class, 'driver_id');
    }

    /**
     * Relasi booking service yang ditugaskan ke teknisi
     */
    public function technicianServiceBookings()
    {
        return $this->hasMany(ServiceBooking::class, 'technician_id');
    }

    /**
     * Relasi jawaban / response teknisi
     */
    public function technicianResponses()
    {
        return $this->hasMany(TechnicianResponse::class, 'technician_id');
    }

    /**
     * Helper: cek apakah user adalah admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Helper: cek apakah user adalah driver.
     */
    public function isDriver(): bool
    {
        return $this->role === 'driver';
    }

    /**
     * Helper: cek apakah user adalah teknisi.
     */
    public function isTechnician(): bool
    {
        return $this->role === 'teknisi';
    }
}