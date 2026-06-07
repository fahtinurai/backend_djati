<?php

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

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    protected $appends = [
        'has_running_activity',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function activeDamageReports()
    {
        return $this->hasMany(DamageReport::class, 'vehicle_id', 'vehicle_id')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $this->finishedStatuses());
            });
    }

    public function activeServiceBookings()
    {
        return $this->hasMany(ServiceBooking::class, 'vehicle_id', 'vehicle_id')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $this->finishedStatuses());
            });
    }

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

    public function hasRunningActivity(): bool
    {
        $hasActiveDamageReport = false;
        $hasActiveServiceBooking = false;

        if (class_exists(DamageReport::class)) {
            $hasActiveDamageReport = $this->activeDamageReports()->exists();
        }

        if (class_exists(ServiceBooking::class)) {
            $hasActiveServiceBooking = $this->activeServiceBookings()->exists();
        }

        return $hasActiveDamageReport || $hasActiveServiceBooking;
    }

    public function getHasRunningActivityAttribute(): bool
    {
        return $this->hasRunningActivity();
    }
}