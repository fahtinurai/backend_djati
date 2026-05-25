<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianResponse extends Model
{
    protected $table = 'technician_responses';

    protected $fillable = [
        'damage_id',
        'technician_id',
        'status',
        'note',
        'mttr',
        'mtbf',
        'ma',
    ];

    protected $casts = [
        'mttr' => 'decimal:2',
        'mtbf' => 'decimal:2',
        'ma' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relasi ke damage report.
     */
    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_id');
    }

    /**
     * Relasi ke teknisi.
     */
    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}