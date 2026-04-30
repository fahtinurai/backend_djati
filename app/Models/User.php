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
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Relasi ke kendaraan yang di-assign ke driver
     */
    public function vehicleAssignments()
    {
        return $this->hasMany(VehicleAssignment::class, 'driver_id');
    }

    /**
     * Relasi laporan kerusakan (driver)
     */
    public function damageReports()
    {
        return $this->hasMany(DamageReport::class, 'driver_id');
    }

    /**
     * Relasi jawaban teknisi
     */
    public function technicianResponses()
    {
        return $this->hasMany(TechnicianResponse::class, 'technician_id');
    }
}
