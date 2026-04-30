<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairPart extends Model
{
  protected $fillable = ['repair_id','part_id','qty'];

  protected $casts = ['qty' => 'integer'];

  public function repair() {
    return $this->belongsTo(Repair::class);
  }

  public function part() {
    return $this->belongsTo(Part::class);
  }
}
