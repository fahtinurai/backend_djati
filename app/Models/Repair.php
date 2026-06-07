<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\DamageReport;
use App\Models\User;
use App\Models\RepairPart;

class Repair extends Model
{
    protected $table = 'repairs';

    protected $fillable = [
        'damage_report_id',
        'vehicle_plate',
        'technician_id',
        'action',
        'cost',
        'repair_date',
        'finalized',
        'finalized_at',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'repair_date' => 'date',
        'finalized' => 'boolean',
        'finalized_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Repair selalu terkait ke DamageReport
     */
    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
    }

    /**
     * Teknisi yang mengerjakan repair
     */
    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Item / parts dalam repair
     */
    public function items()
    {
        return $this->hasMany(RepairPart::class, 'repair_id');
    }

    /**
     * Alias untuk compatibility lama
     */
    public function parts()
    {
        return $this->items();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS (biar aman di workflow kamu)
    |--------------------------------------------------------------------------
    */

    /**
     * Status repair selesai atau belum
     */
    public function isFinalized(): bool
    {
        return (bool) $this->finalized;
    }

    /**
     * Mark repair sebagai selesai
     */
    public function finalize(): void
    {
        $this->update([
            'finalized' => true,
            'finalized_at' => now(),
        ]);
    }

    /**
     * Total cost dari parts (kalau ada breakdown)
     */
    public function getTotalPartsCostAttribute(): float
    {
        return (float) $this->items->sum('cost');
    }
}