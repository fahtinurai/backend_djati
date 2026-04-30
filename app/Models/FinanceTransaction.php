<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceTransaction extends Model
{
  protected $fillable = [
    'type','category','amount','date','note','source','ref','locked','qty','unit_price','meta'
  ];

  protected $casts = [
    'amount' => 'decimal:2',
    'date' => 'date',
    'locked' => 'boolean',
    'qty' => 'integer',
    'unit_price' => 'decimal:2',
    'meta' => 'array',
  ];
}
