<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\DamageReport;
use App\Models\User;

class TechnicianResponse extends Model
{
    protected $table = 'technician_responses';

    /*
    |--------------------------------------------------------------------------
    | MASS ASSIGNMENT
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'damage_id',
        'technician_id',
        'status',
        'note',

        // KPI teknisi
        'mttr',
        'mtbf',
        'ma',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */
    protected $casts = [
        'mttr' => 'decimal:2',
        'mtbf' => 'decimal:2',
        'ma'   => 'decimal:2',

        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS (FIXED & SAFE)
    |--------------------------------------------------------------------------
    */

    /**
     * Relasi ke DamageReport
     * (dipakai di: show report, technician dashboard, booking flow)
     */
    public function damageReport()
    {
        return $this->belongsTo(DamageReport::class, 'damage_id');
    }

    /**
     * Relasi ke User (Technician)
     */
    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS (SAFE FOR UI & FLOW)
    |--------------------------------------------------------------------------
    */

    /**
     * Cek apakah status sedang proses
     */
    public function isInProgress(): bool
    {
        return $this->status === 'proses';
    }

    /**
     * Status butuh follow up admin
     */
    public function isWaitingAdmin(): bool
    {
        return $this->status === 'butuh_followup_admin';
    }

    /**
     * Status selesai
     */
    public function isFinished(): bool
    {
        return $this->status === 'selesai';
    }

    /**
     * Status fatal
     */
    public function isFatal(): bool
    {
        return $this->status === 'fatal';
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS NORMALIZATION (SYNC DENGAN CONTROLLER KAMU)
    |--------------------------------------------------------------------------
    */

    /**
     * Samakan status dari Flutter / API lain
     */
    public function normalizeStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return 'menunggu';
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            'reported',
            'waiting',
            'menunggu' => 'menunggu',

            'ongoing',
            'in_progress',
            'diproses',
            'proses' => 'proses',

            'on_hold',
            'waiting_parts',
            'butuh_followup',
            'butuh_followup_admin' => 'butuh_followup_admin',

            'finished',
            'completed',
            'selesai' => 'selesai',

            'fatal' => 'fatal',

            default => $status,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | BOOT (OPTIONAL SAFETY HOOK)
    |--------------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // pastikan status selalu ternormalisasi
            if ($model->status) {
                $model->status = (new self())->normalizeStatus($model->status);
            }
        });

        static::updating(function ($model) {
            if ($model->status) {
                $model->status = (new self())->normalizeStatus($model->status);
            }
        });
    }
}