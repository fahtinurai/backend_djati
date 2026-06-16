<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DamageReport extends Model
{
    protected $table = 'damage_reports';

    /*
    |--------------------------------------------------------------------------
    | MASS ASSIGNMENT
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'damage_type',
        'description',
        'image',
        'status',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTING
    |--------------------------------------------------------------------------
    */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | APPENDS
    |--------------------------------------------------------------------------
    |
    | Penyesuaian:
    | - image_url tetap untuk akses gambar
    | - computed_status tetap untuk status laporan
    | - responsible_technician_id untuk mengetahui teknisi yang bertanggung jawab
    | - part_usage_summary untuk membantu mobile mengetahui progress sparepart
    | - has_rejected_part_usage untuk menandai apakah ada request sparepart ditolak
    | - latest_rejected_part_usage_note untuk menampilkan alasan penolakan terbaru
    |
    */
    protected $appends = [
        'image_url',
        'computed_status',
        'computed_status_label',
        'responsible_technician_id',

        /*
        |--------------------------------------------------------------------------
        | INFO REQUEST SPAREPART UNTUK TEKNISI
        |--------------------------------------------------------------------------
        */
        'part_usage_summary',
        'has_rejected_part_usage',
        'latest_rejected_part_usage_note',

        /*
        |--------------------------------------------------------------------------
        | COMPATIBILITY DENGAN VEHICLE PAGE / VEHICLE ASSIGNMENT
        |--------------------------------------------------------------------------
        */
        'vehicle_equipment_name',
        'vehicle_plate_number',
        'vehicle_serial_number',
        'vehicle_initial_kpi',
        'vehicle_initial_hour_meter',
        'vehicle_target_availability',
        'vehicle_status',
    ];

    /*
    |--------------------------------------------------------------------------
    | IMAGE URL
    |--------------------------------------------------------------------------
    */
    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        if (str_starts_with($this->image, 'http')) {
            return $this->image;
        }

        if (Storage::disk('public')->exists($this->image)) {
            return asset('storage/' . $this->image);
        }

        return asset('storage/' . $this->image);
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function technicianResponses()
    {
        return $this->hasMany(TechnicianResponse::class, 'damage_id', 'id')
            ->orderBy('created_at', 'asc');
    }

    public function latestTechnicianResponse()
    {
        return $this->hasOne(TechnicianResponse::class, 'damage_id', 'id')
            ->latestOfMany('updated_at');
    }

    public function booking()
    {
        return $this->hasOne(ServiceBooking::class, 'damage_report_id', 'id');
    }

    public function costEstimate()
    {
        return $this->hasOne(CostEstimate::class, 'damage_report_id', 'id');
    }

    public function review()
    {
        return $this->hasOne(TechnicianReview::class, 'damage_report_id', 'id');
    }

    /*
    |--------------------------------------------------------------------------
    | SPAREPART USAGE RELATIONS
    |--------------------------------------------------------------------------
    |
    | PENTING:
    | Flow baru request sparepart memakai model TechnicianPartUsage.
    |
    | Jadi relasi ini sengaja diarahkan ke TechnicianPartUsage,
    | bukan PartUsage, supaya data yang dibuat teknisi dan diproses admin
    | bisa terbaca kembali di sisi teknisi.
    |
    | Response Laravel:
    | - partUsages() akan menjadi part_usages
    |
    | Data inilah yang nanti dibaca Flutter:
    | - requested / pending  = menunggu approval admin
    | - approved             = disetujui admin
    | - rejected             = ditolak admin
    |
    */

    public function partUsages()
    {
        return $this->hasMany(TechnicianPartUsage::class, 'damage_report_id', 'id')
            ->with([
                'part:id,name,sku,stock,buy_price',
                'technician:id,username,role',
            ])
            ->orderBy('created_at', 'desc');
    }

    public function pendingPartUsages()
    {
        return $this->hasMany(TechnicianPartUsage::class, 'damage_report_id', 'id')
            ->whereIn('status', ['pending', 'requested'])
            ->with([
                'part:id,name,sku,stock,buy_price',
                'technician:id,username,role',
            ])
            ->orderBy('created_at', 'desc');
    }

    public function approvedPartUsages()
    {
        return $this->hasMany(TechnicianPartUsage::class, 'damage_report_id', 'id')
            ->where('status', 'approved')
            ->with([
                'part:id,name,sku,stock,buy_price',
                'technician:id,username,role',
            ])
            ->orderBy('created_at', 'desc');
    }

    public function rejectedPartUsages()
    {
        return $this->hasMany(TechnicianPartUsage::class, 'damage_report_id', 'id')
            ->where('status', 'rejected')
            ->with([
                'part:id,name,sku,stock,buy_price',
                'technician:id,username,role',
            ])
            ->orderBy('created_at', 'desc');
    }

    /*
    |--------------------------------------------------------------------------
    | SPAREPART USAGE ACCESSORS
    |--------------------------------------------------------------------------
    |
    | Accessor ini membantu frontend/mobile membaca ringkasan request sparepart.
    | Misalnya teknisi bisa tahu ada request yang ditolak tanpa harus menghitung
    | manual satu per satu di Flutter.
    |
    */

    private function resolvedPartUsages()
    {
        if ($this->relationLoaded('partUsages')) {
            return $this->partUsages;
        }

        return $this->partUsages()->get();
    }

    private function normalizePartUsageStatus(?string $status): string
    {
        if (empty($status)) {
            return 'requested';
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            'pending',
            'requested',
            'menunggu' => 'requested',

            'approve',
            'approved',
            'disetujui' => 'approved',

            'reject',
            'rejected',
            'ditolak' => 'rejected',

            default => $status,
        };
    }

    private function cleanAdminPartUsageNote(?string $note): ?string
    {
        if (empty($note)) {
            return null;
        }

        $note = str_replace('[ADMIN-REJECT]', '', $note);
        $note = str_replace('[ADMIN]', '', $note);

        $note = trim($note);

        return $note !== '' ? $note : null;
    }

    public function getPartUsageSummaryAttribute(): array
    {
        $usages = $this->resolvedPartUsages();

        $summary = [
            'total' => 0,
            'requested' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];

        foreach ($usages as $usage) {
            $summary['total']++;

            $status = $this->normalizePartUsageStatus($usage->status ?? null);

            if ($status === 'approved') {
                $summary['approved']++;
            } elseif ($status === 'rejected') {
                $summary['rejected']++;
            } else {
                $summary['requested']++;
            }
        }

        return $summary;
    }

    public function getHasRejectedPartUsageAttribute(): bool
    {
        $usages = $this->resolvedPartUsages();

        foreach ($usages as $usage) {
            if ($this->normalizePartUsageStatus($usage->status ?? null) === 'rejected') {
                return true;
            }
        }

        return false;
    }

    public function getLatestRejectedPartUsageNoteAttribute(): ?string
    {
        $usages = $this->resolvedPartUsages();

        foreach ($usages as $usage) {
            if ($this->normalizePartUsageStatus($usage->status ?? null) === 'rejected') {
                return $this->cleanAdminPartUsageNote($usage->note ?? null);
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | VEHICLE COMPATIBILITY ACCESSORS
    |--------------------------------------------------------------------------
    |
    | Bagian ini tidak mengubah struktur database.
    | Tujuannya hanya supaya response DamageReport tetap nyambung dengan:
    | - VehiclesPage.jsx
    | - VehicleAssignment
    | - Driver DamageReportPage.dart
    |
    | Database lama:
    | - vehicles.initial_kpi
    |
    | Tampilan baru:
    | - initial_hour_meter
    |
    */

    private function resolvedVehicle(): ?Vehicle
    {
        if ($this->relationLoaded('vehicle')) {
            return $this->vehicle;
        }

        return $this->vehicle()->first();
    }

    public function getVehicleEquipmentNameAttribute(): ?string
    {
        $vehicle = $this->resolvedVehicle();

        return $vehicle?->equipment_name;
    }

    public function getVehiclePlateNumberAttribute(): ?string
    {
        $vehicle = $this->resolvedVehicle();

        return $vehicle?->plate_number;
    }

    public function getVehicleSerialNumberAttribute(): ?string
    {
        $vehicle = $this->resolvedVehicle();

        return $vehicle?->serial_number;
    }

    public function getVehicleInitialKpiAttribute()
    {
        $vehicle = $this->resolvedVehicle();

        return $vehicle?->initial_kpi;
    }

    public function getVehicleInitialHourMeterAttribute()
    {
        $vehicle = $this->resolvedVehicle();

        return $vehicle?->initial_hour_meter ?? $vehicle?->initial_kpi ?? 0;
    }

    public function getVehicleTargetAvailabilityAttribute()
    {
        $vehicle = $this->resolvedVehicle();

        return $vehicle?->target_availability ?? $vehicle?->target_ma ?? 90;
    }

    public function getVehicleStatusAttribute(): string
    {
        $vehicle = $this->resolvedVehicle();

        return $vehicle?->status ?? $vehicle?->unit_status ?? 'active';
    }

    /*
    |--------------------------------------------------------------------------
    | COMPUTED STATUS
    |--------------------------------------------------------------------------
    */

    public function getComputedStatusAttribute(): string
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : $this->latestTechnicianResponse()->first();

        $booking = $this->relationLoaded('booking')
            ? $this->booking
            : $this->booking()->first();

        /*
        |--------------------------------------------------------------------------
        | Prioritas status:
        | 1. Jika service booking sudah completed, maka damage report dianggap selesai.
        | 2. Jika technician response ada, pakai status response teknisi.
        | 3. Jika tidak ada, pakai status damage_reports.
        |--------------------------------------------------------------------------
        */
        if (
            $booking &&
            in_array($this->normalizeStatus($booking->status), ['selesai'], true)
        ) {
            return 'selesai';
        }

        $status = $latest?->status
            ?? $this->status
            ?? 'menunggu';

        return $this->normalizeStatus($status);
    }

    public function getComputedStatusLabelAttribute(): string
    {
        return match ($this->computed_status) {
            'menunggu' => 'Reported',
            'proses' => 'In Progress',
            'butuh_followup_admin' => 'Waiting Parts',
            'approved_followup_admin' => 'Follow-up Approved',
            'fatal' => 'Fatal',
            'selesai' => 'Completed',
            'rejected' => 'Rejected',
            'canceled' => 'Canceled',
            default => 'Reported',
        };
    }

    public function getResponsibleTechnicianIdAttribute(): ?int
    {
        $latest = $this->relationLoaded('latestTechnicianResponse')
            ? $this->latestTechnicianResponse
            : $this->latestTechnicianResponse()->first();

        $booking = $this->relationLoaded('booking')
            ? $this->booking
            : $this->booking()->first();

        $technicianId =
            $latest?->technician_id
            ?? $booking?->technician_id
            ?? null;

        return $technicianId ? (int) $technicianId : null;
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS HELPERS
    |--------------------------------------------------------------------------
    */

    public function isReported(): bool
    {
        return $this->computed_status === 'menunggu';
    }

    public function isInProgress(): bool
    {
        return $this->computed_status === 'proses';
    }

    public function isOnHold(): bool
    {
        return $this->computed_status === 'butuh_followup_admin';
    }

    public function isFollowUpApproved(): bool
    {
        return $this->computed_status === 'approved_followup_admin';
    }

    public function isFatal(): bool
    {
        return $this->computed_status === 'fatal';
    }

    public function isFinished(): bool
    {
        return $this->computed_status === 'selesai';
    }

    public function isRejected(): bool
    {
        return $this->computed_status === 'rejected';
    }

    public function isCanceled(): bool
    {
        return $this->computed_status === 'canceled';
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZER
    |--------------------------------------------------------------------------
    */

    public function normalizeStatus(?string $status): string
    {
        if (empty($status)) {
            return 'menunggu';
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            /*
            |--------------------------------------------------------------------------
            | Waiting / Reported
            |--------------------------------------------------------------------------
            */
            'reported',
            'waiting',
            'pending',
            'requested',
            'menunggu' => 'menunggu',

            /*
            |--------------------------------------------------------------------------
            | In Progress
            |--------------------------------------------------------------------------
            */
            'approved',
            'scheduled',
            'rescheduled',
            'ongoing',
            'in_progress',
            'progress',
            'on_progress',
            'diproses',
            'proses',
            'started',
            'job_started',
            'repair_started',
            'technician_started',
            'working' => 'proses',

            /*
            |--------------------------------------------------------------------------
            | Waiting Parts / Follow-up
            |--------------------------------------------------------------------------
            */
            'on_hold',
            'waiting_parts',
            'menunggu_sparepart',
            'butuh_followup',
            'butuh_followup_admin' => 'butuh_followup_admin',

            /*
            |--------------------------------------------------------------------------
            | Follow-up Approved
            |--------------------------------------------------------------------------
            */
            'approved_followup_admin',
            'followup_approved',
            'follow_up_approved' => 'approved_followup_admin',

            /*
            |--------------------------------------------------------------------------
            | Finished / Completed
            |--------------------------------------------------------------------------
            */
            'finished',
            'completed',
            'complete',
            'done',
            'closed',
            'selesai' => 'selesai',

            /*
            |--------------------------------------------------------------------------
            | Fatal
            |--------------------------------------------------------------------------
            */
            'fatal',
            'critical' => 'fatal',

            /*
            |--------------------------------------------------------------------------
            | Rejected / Canceled
            |--------------------------------------------------------------------------
            */
            'reject',
            'rejected',
            'ditolak' => 'rejected',

            'cancel',
            'canceled',
            'cancelled',
            'dibatalkan' => 'canceled',

            default => 'menunggu',
        };
    }
}