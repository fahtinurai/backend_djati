<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\DamageReport;
use App\Models\Part;
use App\Models\FinanceTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            $weeklyDowntimeChart = $this->getWeeklyDowntimeChart();

            $currentWeekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
            $currentWeekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

            $previousWeekStart = Carbon::now()->subWeek()->startOfWeek(Carbon::MONDAY);
            $previousWeekEnd = Carbon::now()->subWeek()->endOfWeek(Carbon::SUNDAY);

            $downtimeHours = $this->getDowntimeHoursForRange(
                $currentWeekStart,
                $currentWeekEnd
            );

            $previousDowntimeHours = $this->getDowntimeHoursForRange(
                $previousWeekStart,
                $previousWeekEnd
            );

            $currentMonthStart = Carbon::now()->startOfMonth();
            $currentMonthEnd = Carbon::now()->endOfMonth();

            $previousMonthStart = Carbon::now()->subMonth()->startOfMonth();
            $previousMonthEnd = Carbon::now()->subMonth()->endOfMonth();

            $maintenanceTasks = $this->countMaintenanceTasksForRange(
                $currentMonthStart,
                $currentMonthEnd
            );

            $previousMaintenanceTasks = $this->countMaintenanceTasksForRange(
                $previousMonthStart,
                $previousMonthEnd
            );

            $latestReports = $this->getLatestReports(5);

            return response()->json([
                'drivers' => User::where('role', 'driver')->count(),

                'technicians' => User::whereIn('role', [
                    'technician',
                    'teknisi',
                    'mechanic',
                    'mekanik',
                ])->count(),

                'vehicles' => Vehicle::count(),

                'followups' => $this->getFollowUpCount(),

                'parts' => Part::count(),

                'transactions' => FinanceTransaction::count(),

                'downtime_hours' => round($downtimeHours, 2),
                'downtimeHours' => round($downtimeHours, 2),

                'downtime_change' => $this->formatPercentageChange(
                    $downtimeHours,
                    $previousDowntimeHours
                ),
                'downtimeChange' => $this->formatPercentageChange(
                    $downtimeHours,
                    $previousDowntimeHours
                ),

                'downtime_change_direction' => $this->getChangeDirection(
                    $downtimeHours,
                    $previousDowntimeHours
                ),
                'downtimeChangeDirection' => $this->getChangeDirection(
                    $downtimeHours,
                    $previousDowntimeHours
                ),

                'maintenance_tasks' => $maintenanceTasks,
                'maintenanceTasks' => $maintenanceTasks,

                'maintenance_change' => $this->formatPercentageChange(
                    $maintenanceTasks,
                    $previousMaintenanceTasks
                ),
                'maintenanceChange' => $this->formatPercentageChange(
                    $maintenanceTasks,
                    $previousMaintenanceTasks
                ),

                'maintenance_change_direction' => $this->getChangeDirection(
                    $maintenanceTasks,
                    $previousMaintenanceTasks
                ),
                'maintenanceChangeDirection' => $this->getChangeDirection(
                    $maintenanceTasks,
                    $previousMaintenanceTasks
                ),

                'downtime_chart' => $weeklyDowntimeChart,
                'downtimeChart' => $weeklyDowntimeChart,

                'weekly_downtime' => $weeklyDowntimeChart,
                'weeklyDowntime' => $weeklyDowntimeChart,

                'bar_chart' => $weeklyDowntimeChart,
                'barChart' => $weeklyDowntimeChart,

                'latest_reports' => $latestReports,
                'latestReports' => $latestReports,

                'latest_damage_reports' => $latestReports,
                'latestDamageReports' => $latestReports,
            ]);
        } catch (\Throwable $e) {
            Log::error('DashboardController@index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Gagal mengambil data dashboard.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function chart()
    {
        try {
            return response()->json([
                'data' => $this->getWeeklyDowntimeChart(),
            ]);
        } catch (\Throwable $e) {
            Log::error('DashboardController@chart error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Gagal mengambil chart dashboard.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getFollowUpCount(): int
    {
        try {
            $model = new DamageReport();

            $hasStatusColumn = Schema::hasColumn('damage_reports', 'status');
            $hasLatestResponseRelation = method_exists($model, 'latestTechnicianResponse');

            if (!$hasStatusColumn && !$hasLatestResponseRelation) {
                return 0;
            }

            return DamageReport::query()
                ->where(function ($query) use ($hasStatusColumn, $hasLatestResponseRelation) {
                    if ($hasStatusColumn) {
                        $query->where('status', 'butuh_followup_admin');
                    }

                    if ($hasLatestResponseRelation) {
                        $query->orWhereHas('latestTechnicianResponse', function ($q) {
                            $q->where('status', 'butuh_followup_admin');
                        });
                    }
                })
                ->count();
        } catch (\Throwable $e) {
            Log::error('Dashboard followup count error: ' . $e->getMessage());

            return 0;
        }
    }

    private function getLatestReports(int $limit = 5)
    {
        try {
            $model = new DamageReport();

            $relations = [];

            if (method_exists($model, 'vehicle')) {
                $relations[] = 'vehicle';
            }

            if (method_exists($model, 'driver')) {
                $relations[] = 'driver';
            }

            if (method_exists($model, 'latestTechnicianResponse')) {
                $relations[] = 'latestTechnicianResponse.technician';
            }

            if (method_exists($model, 'booking')) {
                $relations[] = 'booking';
            }

            if (method_exists($model, 'serviceBooking')) {
                $relations[] = 'serviceBooking';
            }

            if (method_exists($model, 'latestServiceBooking')) {
                $relations[] = 'latestServiceBooking';
            }

            $query = DamageReport::query();

            if (!empty($relations)) {
                $query->with($relations);
            }

            if (Schema::hasColumn('damage_reports', 'created_at')) {
                $query->orderByDesc('created_at');
            } else {
                $query->orderByDesc('id');
            }

            return $query
                ->limit($limit)
                ->get()
                ->map(function ($report) {
                    return $this->serializeLatestReport($report);
                })
                ->values();
        } catch (\Throwable $e) {
            Log::error('Dashboard latest reports error: ' . $e->getMessage());

            return collect();
        }
    }

    private function serializeLatestReport($report): array
    {
        $vehicle = $this->getLoadedRelation($report, ['vehicle']);
        $driver = $this->getLoadedRelation($report, ['driver']);
        $latestResponse = $this->getLoadedRelation($report, ['latestTechnicianResponse']);

        $booking = $this->getLoadedRelation($report, [
            'booking',
            'serviceBooking',
            'latestServiceBooking',
        ]);

        $status = $this->resolveReportStatus($report, $latestResponse, $booking);

        $equipmentName = $this->firstValid(
            data_get($vehicle, 'equipment_name'),
            data_get($vehicle, 'name'),
            data_get($vehicle, 'unit_name'),
            data_get($report, 'equipment_name')
        );

        $plateNumber = $this->firstValid(
            data_get($vehicle, 'plate_number'),
            data_get($vehicle, 'plate'),
            data_get($report, 'plate_number')
        );

        $driverName = $this->firstValid(
            data_get($driver, 'name'),
            data_get($driver, 'username'),
            data_get($driver, 'email')
        );

        $serializedBooking = $booking
            ? $this->serializeBooking($booking)
            : null;

        return [
            'id' => data_get($report, 'id'),
            'damage_report_id' => data_get($report, 'id'),
            'damageReportId' => data_get($report, 'id'),

            'equipment_name' => $equipmentName,
            'equipmentName' => $equipmentName,

            'plate_number' => $plateNumber,
            'plateNumber' => $plateNumber,

            'driver_name' => $driverName,
            'driverName' => $driverName,
            'operator' => $driverName,

            'damage_type' => data_get($report, 'damage_type'),
            'damageType' => data_get($report, 'damage_type'),

            'description' => data_get($report, 'description'),

            'status' => data_get($report, 'status'),
            'computed_status' => $status,
            'computedStatus' => $status,

            'created_at' => $this->dateTimeValue(data_get($report, 'created_at')),
            'createdAt' => $this->dateTimeValue(data_get($report, 'created_at')),

            'updated_at' => $this->dateTimeValue(data_get($report, 'updated_at')),
            'updatedAt' => $this->dateTimeValue(data_get($report, 'updated_at')),

            'vehicle' => $vehicle ? [
                'id' => data_get($vehicle, 'id'),
                'equipment_name' => data_get($vehicle, 'equipment_name'),
                'equipmentName' => data_get($vehicle, 'equipment_name'),
                'name' => data_get($vehicle, 'name'),
                'unit_name' => data_get($vehicle, 'unit_name'),
                'plate_number' => data_get($vehicle, 'plate_number'),
                'plateNumber' => data_get($vehicle, 'plate_number'),
                'plate' => data_get($vehicle, 'plate'),
                'brand' => data_get($vehicle, 'brand'),
                'model' => data_get($vehicle, 'model'),
                'type' => data_get($vehicle, 'type'),
                'vehicle_type' => data_get($vehicle, 'vehicle_type'),
                'equipment_type' => data_get($vehicle, 'equipment_type'),
            ] : null,

            'driver' => $driver ? [
                'id' => data_get($driver, 'id'),
                'name' => data_get($driver, 'name'),
                'username' => data_get($driver, 'username'),
                'email' => data_get($driver, 'email'),
                'role' => data_get($driver, 'role'),
            ] : null,

            'latest_technician_response' => $latestResponse
                ? $this->serializeTechnicianResponse($latestResponse)
                : null,

            'latestTechnicianResponse' => $latestResponse
                ? $this->serializeTechnicianResponse($latestResponse)
                : null,

            'booking' => $serializedBooking,
            'service_booking' => $serializedBooking,
            'serviceBooking' => $serializedBooking,
            'latest_service_booking' => $serializedBooking,
            'latestServiceBooking' => $serializedBooking,
        ];
    }

    private function serializeTechnicianResponse($response): array
    {
        $technician = null;

        if ($response && method_exists($response, 'relationLoaded')) {
            $technician = $response->relationLoaded('technician')
                ? $response->technician
                : null;
        }

        return [
            'id' => data_get($response, 'id'),
            'damage_id' => data_get($response, 'damage_id'),
            'damage_report_id' => data_get($response, 'damage_id'),
            'technician_id' => data_get($response, 'technician_id'),
            'status' => data_get($response, 'status'),
            'note' => data_get($response, 'note'),
            'response_note' => data_get($response, 'response_note'),
            'mttr' => data_get($response, 'mttr'),
            'mtbf' => data_get($response, 'mtbf'),
            'ma' => data_get($response, 'ma'),
            'created_at' => $this->dateTimeValue(data_get($response, 'created_at')),
            'updated_at' => $this->dateTimeValue(data_get($response, 'updated_at')),

            'technician' => $technician ? [
                'id' => data_get($technician, 'id'),
                'name' => data_get($technician, 'name'),
                'username' => data_get($technician, 'username'),
                'email' => data_get($technician, 'email'),
            ] : null,
        ];
    }

    private function serializeBooking($booking): array
    {
        return [
            'id' => data_get($booking, 'id'),
            'damage_report_id' => data_get($booking, 'damage_report_id'),
            'technician_id' => data_get($booking, 'technician_id'),
            'status' => data_get($booking, 'status'),
            'priority' => data_get($booking, 'priority'),
            'note_admin' => data_get($booking, 'note_admin'),
            'admin_note' => data_get($booking, 'admin_note'),
            'note_technician' => data_get($booking, 'note_technician'),
            'mttr' => data_get($booking, 'mttr'),
            'mtbf' => data_get($booking, 'mtbf'),
            'ma' => data_get($booking, 'ma'),
            'scheduled_at' => $this->dateTimeValue(data_get($booking, 'scheduled_at')),
            'completed_at' => $this->dateTimeValue(data_get($booking, 'completed_at')),
            'created_at' => $this->dateTimeValue(data_get($booking, 'created_at')),
            'updated_at' => $this->dateTimeValue(data_get($booking, 'updated_at')),
        ];
    }

    private function resolveReportStatus($report, $latestResponse = null, $booking = null): string
    {
        $status = $this->firstValid(
            data_get($report, 'computed_status'),
            data_get($latestResponse, 'status'),
            data_get($booking, 'status'),
            data_get($report, 'status'),
            'menunggu'
        );

        return $this->normalizeStatus($status);
    }

    private function getWeeklyDowntimeChart(): array
    {
        $startOfWeek = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $items = [];
        $values = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $startOfWeek->copy()->addDays($i);

            $value = $this->getDowntimeHoursForRange(
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay()
            );

            $values[] = $value;

            $items[] = [
                'day' => $day->format('D'),
                'label' => $day->format('D'),
                'value' => round($value, 2),
                'hours' => round($value, 2),
                'active' => $day->isSameDay(Carbon::now()),
            ];
        }

        $max = max($values) > 0 ? max($values) : 1;

        return array_map(function ($item) use ($max) {
            $height = $item['value'] > 0
                ? round(($item['value'] / $max) * 100)
                : 0;

            $height = max(0, min($height, 100));

            return array_merge($item, [
                'h' => "{$height}%",
                'height' => "{$height}%",
            ]);
        }, $items);
    }

    private function getDowntimeHoursForRange(Carbon $start, Carbon $end): float
    {
        try {
            if (
                !Schema::hasTable('technician_responses') ||
                !Schema::hasColumn('technician_responses', 'mttr')
            ) {
                return 0;
            }

            $query = DB::table('technician_responses')
                ->whereNotNull('mttr');

            $dateColumn = $this->firstExistingColumn('technician_responses', [
                'created_at',
                'updated_at',
            ]);

            if ($dateColumn) {
                $query->whereBetween($dateColumn, [
                    $start->toDateTimeString(),
                    $end->toDateTimeString(),
                ]);
            }

            if (Schema::hasColumn('technician_responses', 'status')) {
                $query->whereIn('status', [
                    'selesai',
                    'completed',
                    'finished',
                    'complete',
                ]);
            }

            return (float) $query->sum('mttr');
        } catch (\Throwable $e) {
            Log::error('Dashboard downtime error: ' . $e->getMessage());

            return 0;
        }
    }

    private function countMaintenanceTasksForRange(Carbon $start, Carbon $end): int
    {
        try {
            if (Schema::hasTable('repairs')) {
                $query = DB::table('repairs');

                $dateColumn = $this->firstExistingColumn('repairs', [
                    'finalized_at',
                    'repair_date',
                    'updated_at',
                    'created_at',
                ]);

                if ($dateColumn) {
                    if ($dateColumn === 'repair_date') {
                        $query->whereBetween($dateColumn, [
                            $start->toDateString(),
                            $end->toDateString(),
                        ]);
                    } else {
                        $query->whereBetween($dateColumn, [
                            $start->toDateTimeString(),
                            $end->toDateTimeString(),
                        ]);
                    }
                }

                if (Schema::hasColumn('repairs', 'finalized')) {
                    $query->where('finalized', true);
                } elseif (Schema::hasColumn('repairs', 'status')) {
                    $query->whereIn('status', [
                        'completed',
                        'finished',
                        'selesai',
                    ]);
                }

                return (int) $query->count();
            }

            if (Schema::hasTable('service_bookings')) {
                $query = DB::table('service_bookings');

                $dateColumn = $this->firstExistingColumn('service_bookings', [
                    'completed_at',
                    'updated_at',
                    'created_at',
                ]);

                if ($dateColumn) {
                    $query->whereBetween($dateColumn, [
                        $start->toDateTimeString(),
                        $end->toDateTimeString(),
                    ]);
                }

                if (Schema::hasColumn('service_bookings', 'status')) {
                    $query->whereIn('status', [
                        'completed',
                        'finished',
                        'selesai',
                    ]);
                }

                return (int) $query->count();
            }

            return 0;
        } catch (\Throwable $e) {
            Log::error('Dashboard maintenance task error: ' . $e->getMessage());

            return 0;
        }
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function formatPercentageChange(float|int $current, float|int $previous): string
    {
        if ($previous == 0 && $current == 0) {
            return '0%';
        }

        if ($previous == 0 && $current > 0) {
            return '100%';
        }

        if ($previous == 0) {
            return '0%';
        }

        $percentage = abs((($current - $previous) / $previous) * 100);

        return round($percentage) . '%';
    }

    private function getChangeDirection(float|int $current, float|int $previous): string
    {
        if ($current > $previous) {
            return 'up';
        }

        if ($current < $previous) {
            return 'down';
        }

        return 'same';
    }

    private function normalizeStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            'menunggu',
            'reported',
            'waiting',
            'pending',
            'request',
            'requested' => 'reported',

            'proses',
            'diproses',
            'ongoing',
            'in_progress',
            'progress',
            'on_progress',
            'approved',
            'scheduled',
            'started',
            'working',
            'job_started',
            'repair_started',
            'technician_started' => 'in_progress',

            'butuh_followup_admin',
            'butuh_followup',
            'menunggu_sparepart',
            'waiting_parts',
            'on_hold' => 'waiting_parts',

            'selesai',
            'finished',
            'completed',
            'complete' => 'completed',

            'fatal',
            'critical' => 'fatal',

            'approved_followup_admin',
            'followup_approved',
            'follow_up_approved' => 'approved_followup_admin',

            'rejected',
            'reject',
            'ditolak' => 'rejected',

            'canceled',
            'cancelled',
            'cancel',
            'dibatalkan' => 'canceled',

            default => $status ?: 'reported',
        };
    }

    private function firstValid(...$values)
    {
        foreach ($values as $value) {
            if (
                $value !== null &&
                $value !== '' &&
                $value !== '-' &&
                strtolower(trim((string) $value)) !== 'null' &&
                strtolower(trim((string) $value)) !== 'undefined'
            ) {
                return $value;
            }
        }

        return null;
    }

    private function dateTimeValue($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toISOString')) {
            return $value->toISOString();
        }

        if (is_object($value) && method_exists($value, 'toDateTimeString')) {
            return $value->toDateTimeString();
        }

        return (string) $value;
    }

    private function getLoadedRelation($model, array $relations)
    {
        if (!$model || !method_exists($model, 'relationLoaded')) {
            return null;
        }

        foreach ($relations as $relation) {
            if ($model->relationLoaded($relation)) {
                return $model->{$relation};
            }
        }

        return null;
    }
}