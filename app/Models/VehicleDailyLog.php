<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleDailyLog extends Model
{
    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'log_date',
        'shift',
        'hour_meter_start',
        'hour_meter_end',
        'fuel_liters',
        'note',
    ];

    protected $casts = [
        'log_date' => 'date',
        'hour_meter_start' => 'decimal:2',
        'hour_meter_end' => 'decimal:2',
        'fuel_liters' => 'decimal:2',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function getOperatingHoursAttribute()
    {
        return max(0, (float) $this->hour_meter_end - (float) $this->hour_meter_start);
    }
}