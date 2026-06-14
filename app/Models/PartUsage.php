<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartUsage extends Model
{
    use HasFactory;

    protected $table = 'part_usages';

    protected $fillable = [
        'technician_id',
        'damage_report_id',
        'part_id',
        'qty',
        'note',
        'status',

        // opsional, pakai kalau kolom ini ada di tabel part_usages kamu
        'admin_note',
        'rejection_reason',
        'approved_by',
        'rejected_by',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'qty' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'part_name',
        'part_sku',
        'technician_name',
        'damage_report_number',
        'vehicle_plate',
        'display_status',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function part()
    {
        return $this->belongsTo(Part::class, 'part_id');
    }

    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    | Ini membantu frontend agar tidak bingung ambil nested data.
    | Jadi selain usage.part.name, frontend juga bisa langsung baca:
    | - usage.part_name
    | - usage.part_sku
    | - usage.technician_name
    | - usage.vehicle_plate
    */

    public function getPartNameAttribute()
    {
        return $this->part?->name ?? null;
    }

    public function getPartSkuAttribute()
    {
        return $this->part?->sku ?? null;
    }

    public function getTechnicianNameAttribute()
    {
        return $this->technician?->name
            ?? $this->technician?->username
            ?? null;
    }

    public function getDamageReportNumberAttribute()
    {
        return $this->damage_report_id
            ? '#' . $this->damage_report_id
            : null;
    }

    public function getVehiclePlateAttribute()
    {
        return $this->damageReport?->vehicle?->plate_number ?? null;
    }

    public function getDisplayStatusAttribute()
    {
        return match ($this->status) {
            'requested' => 'Pending',
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'used' => 'Used',
            default => $this->status ? ucfirst($this->status) : 'Pending',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            'pending',
            'requested',
        ]);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForDamageReport($query, $damageReportId)
    {
        return $query->where('damage_report_id', $damageReportId);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function isPending()
    {
        return in_array($this->status, ['pending', 'requested'], true);
    }

    public function isApproved()
    {
        return $this->status === 'approved';
    }

    public function isRejected()
    {
        return $this->status === 'rejected';
    }
}