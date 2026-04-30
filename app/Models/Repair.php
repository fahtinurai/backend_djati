<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Repair extends Model
{
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

  public function damageReport() {
    return $this->belongsTo(DamageReport::class);
  }

  public function technician() {
    return $this->belongsTo(User::class, 'technician_id');
  }

  public function items() {
    return $this->hasMany(RepairPart::class);
  }

  public function parts() {
    return $this->items();
  }
}

