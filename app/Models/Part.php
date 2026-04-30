<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Part extends Model 
{
  protected $fillable = ['name','sku','stock','min_stock','buy_price'];

  protected $casts = [
    'stock' => 'integer',
    'min_stock' => 'integer',
    'buy_price' => 'decimal:2',
  ];

  public function movements() {
    return $this->hasMany(StockMovement::class);
  }
}
