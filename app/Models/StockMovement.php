<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'part_id',
        'type',
        'qty',
        'note',
        'date',
        'ref',
    ];

    public function part()
    {
        return $this->belongsTo(Part::class);
    }
}
