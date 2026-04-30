<?php
// Nur Ahmadi Aditya Nanda
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleAssignment extends Model
{
    protected $table = 'vehicle_assignments';

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'assigned_at',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
