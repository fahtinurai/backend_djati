<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianPartUsage extends Model
{
    protected $fillable = [
        'technician_id',
        'damage_report_id',
        'part_id',
        'qty',
        'status',
        'note',
    ];

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
    }

    public function part()
    {
        return $this->belongsTo(Part::class, 'part_id');
    }
}
