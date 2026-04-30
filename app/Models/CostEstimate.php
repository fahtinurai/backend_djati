<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostEstimate extends Model
{
    protected $table = 'cost_estimates';

    protected $fillable = [
        'damage_report_id',
        'technician_id',
        'labor_cost',
        'parts_cost',
        'other_cost',
        'total_cost',
        'note',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected $attributes = [
        'labor_cost' => 0,
        'parts_cost' => 0,
        'other_cost' => 0,
        'total_cost' => 0,
        'status' => 'draft',
    ];

    protected $casts = [
        'labor_cost'  => 'integer',
        'parts_cost'  => 'integer',
        'other_cost'  => 'integer',
        'total_cost'  => 'integer',
        'approved_at' => 'datetime',
    ];

    // =========================
    // Relations
    // =========================
    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id');
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // =========================
    // Helpers
    // =========================
    public function recomputeTotal(): void
    {
        $labor = (int) ($this->labor_cost ?? 0);
        $parts = (int) ($this->parts_cost ?? 0);
        $other = (int) ($this->other_cost ?? 0);

        $this->total_cost = $labor + $parts + $other;
    }

    // Opsional tapi recommended:
    // total_cost akan otomatis dihitung tiap create/update
    protected static function booted()
    {
        static::saving(function (CostEstimate $m) {
            $m->recomputeTotal();
        });
    }
}
