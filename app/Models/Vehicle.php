<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    // Nama tabel (ikuti migration kamu)
    protected $table = 'vehicles';

    // Kolom yang boleh diisi lewat create()/update()
    protected $fillable = [
        'brand',
        'model',
        'plate_number',
        'year',
    ];

    public function assignment()
    {
        return $this->hasOne(VehicleAssignment::class);
    }

    public function damageReports()
    {
        return $this->hasMany(DamageReport::class);
    }
}