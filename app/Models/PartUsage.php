<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        // isi sesuai kolom tabel part_usages kamu (kalau ada)
        'technician_id',
        'damage_report_id',
        'part_id',
        'qty',
        'note',
        'status',
    ];

    // Relasi contoh (sesuaikan kalau memang ada tabelnya)
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
}
