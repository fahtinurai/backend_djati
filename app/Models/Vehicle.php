<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{

    protected $table = 'vehicles';

    protected $fillable = [
        'equipment_name', 
        'brand',
        'model',
        'plate_number',
        'year',
    ];

    /**
     * Relasi ke assignment (1 kendaraan → 1 driver aktif)
     */
    public function assignment()
    {
        return $this->hasOne(VehicleAssignment::class);
    }

    /**
     * Relasi ke laporan kerusakan
     */
    public function damageReports()
    {
        return $this->hasMany(DamageReport::class);
    }
}