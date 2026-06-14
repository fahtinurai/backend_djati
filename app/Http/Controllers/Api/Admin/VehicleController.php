<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    /**
     * GET /api/admin/vehicles
     */
    public function index()
    {
        $vehicles = Vehicle::orderBy('created_at', 'desc')
            ->get()
            ->map(function ($vehicle) {
                return $this->vehicleResponse($vehicle);
            });

        return response()->json($vehicles);
    }

    /**
     * POST /api/admin/vehicles
     */
    public function store(Request $request)
    {
        $this->normalizeInput($request);

        $validated = $request->validate(
            $this->vehicleRules(),
            $this->vehicleMessages()
        );

        $vehicle = new Vehicle();
        $this->fillVehicle($vehicle, $validated, isCreate: true);
        $vehicle->save();
        $vehicle->refresh();

        $this->publishRealtimeEvent('vehicle.created', $this->vehicleResponse($vehicle));

        return response()->json($this->vehicleResponse($vehicle), 201);
    }

    /**
     * PUT /api/admin/vehicles/{vehicle}
     */
    public function update(Request $request, Vehicle $vehicle)
    {
        $this->normalizeInput($request);

        $oldCurrentHourMeter = $this->getCurrentHourMeterValue($vehicle);

        $validated = $request->validate(
            $this->vehicleRules($vehicle),
            $this->vehicleMessages()
        );

        $this->fillVehicle($vehicle, $validated, isCreate: false);
        $vehicle->save();
        $vehicle->refresh();

        $response = $this->vehicleResponse($vehicle);
        $newCurrentHourMeter = $response['current_hour_meter'] ?? null;

        $this->publishRealtimeEvent('vehicle.updated', $response);

        if ((string) $oldCurrentHourMeter !== (string) $newCurrentHourMeter) {
            $this->publishRealtimeEvent('vehicle.hour_meter.updated', $response);
        }

        return response()->json($response);
    }

    /**
     * DELETE /api/admin/vehicles/{vehicle}
     */
    public function destroy(Vehicle $vehicle)
    {
        $vehicleId = $vehicle->id;

        $vehicle->delete();

        $this->publishRealtimeEvent('vehicle.deleted', [
            'id' => $vehicleId,
        ]);

        return response()->json([
            'message' => 'Kendaraan berhasil dihapus.',
        ]);
    }

    /**
     * Normalisasi input sebelum divalidasi.
     *
     * Catatan penting:
     * - Database lama memakai initial_kpi.
     * - Frontend baru menampilkan initial_hour_meter.
     * - initial_hour_meter selalu dipetakan ke initial_kpi.
     * - current_hour_meter adalah HM terbaru.
     * - current_hour_meter boleh dikirim oleh admin jika ingin koreksi manual.
     * - update otomatis dari teknisi tetap dilakukan di ServiceJobController@complete.
     */
    private function normalizeInput(Request $request): void
    {
        $normalized = [];

        if ($request->has('equipment_name')) {
            $normalized['equipment_name'] = trim((string) $request->equipment_name);
        }

        if ($request->has('plate_number')) {
            $normalized['plate_number'] = trim((string) $request->plate_number);
        }

        if ($request->has('serial_number')) {
            $normalized['serial_number'] = trim((string) $request->serial_number);
        }

        if ($request->has('brand')) {
            $brand = trim((string) $request->brand);
            $normalized['brand'] = $brand !== '' ? $brand : null;
        }

        if ($request->has('model')) {
            $model = trim((string) $request->model);
            $normalized['model'] = $model !== '' ? $model : null;
        }

        if ($request->has('year')) {
            $normalized['year'] = $request->year !== '' ? $request->year : null;
        }

        /**
         * Hour Meter Awal.
         * Database tetap initial_kpi.
         */
        if ($request->has('initial_hour_meter')) {
            $normalized['initial_kpi'] = $request->initial_hour_meter !== ''
                ? $request->initial_hour_meter
                : null;
        } elseif ($request->has('initial_kpi')) {
            $normalized['initial_kpi'] = $request->initial_kpi !== ''
                ? $request->initial_kpi
                : null;
        }

        /**
         * Hour Meter Terbaru.
         * Alias yang diterima:
         * - current_hour_meter
         * - latest_hour_meter
         * - final_hour_meter
         * - hour_meter_terbaru
         *
         * Semua dipetakan ke current_hour_meter.
         */
        if ($request->has('current_hour_meter')) {
            $normalized['current_hour_meter'] = $request->current_hour_meter !== ''
                ? $request->current_hour_meter
                : null;
        } elseif ($request->has('latest_hour_meter')) {
            $normalized['current_hour_meter'] = $request->latest_hour_meter !== ''
                ? $request->latest_hour_meter
                : null;
        } elseif ($request->has('final_hour_meter')) {
            $normalized['current_hour_meter'] = $request->final_hour_meter !== ''
                ? $request->final_hour_meter
                : null;
        } elseif ($request->has('hour_meter_terbaru')) {
            $normalized['current_hour_meter'] = $request->hour_meter_terbaru !== ''
                ? $request->hour_meter_terbaru
                : null;
        }

        if ($request->has('target_availability')) {
            $normalized['target_availability'] = $request->target_availability !== ''
                ? $request->target_availability
                : null;
        }

        if ($request->has('target_ma')) {
            $normalized['target_availability'] = $request->target_ma !== ''
                ? $request->target_ma
                : null;
        }

        /**
         * Mechanical Availability terbaru.
         */
        if ($request->has('current_ma')) {
            $normalized['current_ma'] = $request->current_ma !== ''
                ? $request->current_ma
                : null;
        } elseif ($request->has('ma')) {
            $normalized['current_ma'] = $request->ma !== ''
                ? $request->ma
                : null;
        } elseif ($request->has('mechanical_availability')) {
            $normalized['current_ma'] = $request->mechanical_availability !== ''
                ? $request->mechanical_availability
                : null;
        }

        if ($request->has('status')) {
            $normalized['status'] = $request->status ?: 'active';
        }

        $request->merge($normalized);
    }

    /**
     * Rules validasi kendaraan.
     *
     * equipment_name TIDAK unique.
     * Yang unique hanya:
     * - plate_number
     * - serial_number
     */
    private function vehicleRules(?Vehicle $vehicle = null): array
    {
        $isUpdate = $vehicle !== null;

        $rules = [
            'equipment_name' => array_filter([
                $isUpdate ? 'sometimes' : null,
                'required',
                'string',
                'max:255',
            ]),

            'plate_number' => array_filter([
                $isUpdate ? 'sometimes' : null,
                'required',
                'string',
                'max:255',
                $vehicle
                    ? Rule::unique('vehicles', 'plate_number')->ignore($vehicle->id)
                    : Rule::unique('vehicles', 'plate_number'),
            ]),

            'serial_number' => array_filter([
                $isUpdate ? 'sometimes' : null,
                'required',
                'string',
                'max:255',
                $vehicle
                    ? Rule::unique('vehicles', 'serial_number')->ignore($vehicle->id)
                    : Rule::unique('vehicles', 'serial_number'),
            ]),

            /**
             * Tetap memakai initial_kpi sebagai field database lama.
             * Di tampilan VehiclesPage.jsx namanya menjadi Hour Meter Awal.
             */
            'initial_kpi' => array_filter([
                $isUpdate ? 'sometimes' : null,
                'required',
                'numeric',
                'min:0',
            ]),

            'brand' => array_filter([
                $isUpdate ? 'sometimes' : null,
                'nullable',
                'string',
                'max:255',
            ]),

            'model' => array_filter([
                $isUpdate ? 'sometimes' : null,
                'nullable',
                'string',
                'max:255',
            ]),

            'year' => array_filter([
                $isUpdate ? 'sometimes' : null,
                'nullable',
                'integer',
                'min:1980',
                'max:2100',
            ]),
        ];

        /**
         * Target MA.
         */
        if (Schema::hasColumn('vehicles', 'target_availability')) {
            $rules['target_availability'] = array_filter([
                $isUpdate ? 'sometimes' : null,
                'required',
                'numeric',
                'min:0',
                'max:100',
            ]);
        }

        /**
         * HM terbaru.
         * Field ini optional saat update.
         * Saat create, kalau tidak dikirim, akan otomatis pakai initial_kpi.
         */
        if (
            Schema::hasColumn('vehicles', 'current_hour_meter') ||
            Schema::hasColumn('vehicles', 'latest_hour_meter') ||
            Schema::hasColumn('vehicles', 'final_hour_meter')
        ) {
            $rules['current_hour_meter'] = array_filter([
                $isUpdate ? 'sometimes' : 'nullable',
                'nullable',
                'numeric',
                'min:0',
            ]);
        }

        /**
         * MA terbaru.
         */
        if (
            Schema::hasColumn('vehicles', 'current_ma') ||
            Schema::hasColumn('vehicles', 'ma') ||
            Schema::hasColumn('vehicles', 'mechanical_availability')
        ) {
            $rules['current_ma'] = array_filter([
                $isUpdate ? 'sometimes' : 'nullable',
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ]);
        }

        if (Schema::hasColumn('vehicles', 'status')) {
            $rules['status'] = array_filter([
                $isUpdate ? 'sometimes' : null,
                'nullable',
                Rule::in(['active', 'maintenance', 'inactive']),
            ]);
        }

        return $rules;
    }

    /**
     * Pesan validasi kendaraan.
     */
    private function vehicleMessages(): array
    {
        return [
            'equipment_name.required' => 'Nama unit/equipment wajib diisi.',
            'equipment_name.string'   => 'Nama unit/equipment harus berupa teks.',
            'equipment_name.max'      => 'Nama unit/equipment maksimal 255 karakter.',

            'plate_number.required' => 'Nomor plat/lambung wajib diisi.',
            'plate_number.string'   => 'Nomor plat/lambung harus berupa teks.',
            'plate_number.max'      => 'Nomor plat/lambung maksimal 255 karakter.',
            'plate_number.unique'   => 'Nomor plat/lambung sudah terdaftar.',

            'serial_number.required' => 'Nomor serial mesin wajib diisi.',
            'serial_number.string'   => 'Nomor serial mesin harus berupa teks.',
            'serial_number.max'      => 'Nomor serial mesin maksimal 255 karakter.',
            'serial_number.unique'   => 'Nomor serial mesin sudah terdaftar.',

            'initial_kpi.required' => 'Hour meter awal wajib diisi.',
            'initial_kpi.numeric'  => 'Hour meter awal harus berupa angka.',
            'initial_kpi.min'      => 'Hour meter awal tidak boleh kurang dari 0.',

            'current_hour_meter.numeric' => 'Hour meter terbaru harus berupa angka.',
            'current_hour_meter.min'     => 'Hour meter terbaru tidak boleh kurang dari 0.',

            'target_availability.required' => 'Target MA wajib diisi.',
            'target_availability.numeric'  => 'Target MA harus berupa angka.',
            'target_availability.min'      => 'Target MA tidak boleh kurang dari 0%.',
            'target_availability.max'      => 'Target MA tidak boleh lebih dari 100%.',

            'current_ma.numeric' => 'MA terbaru harus berupa angka.',
            'current_ma.min'     => 'MA terbaru tidak boleh kurang dari 0%.',
            'current_ma.max'     => 'MA terbaru tidak boleh lebih dari 100%.',

            'status.in' => 'Status unit tidak valid.',

            'brand.string' => 'Brand harus berupa teks.',
            'brand.max'    => 'Brand maksimal 255 karakter.',

            'model.string' => 'Model harus berupa teks.',
            'model.max'    => 'Model maksimal 255 karakter.',

            'year.integer' => 'Tahun harus berupa angka.',
            'year.min'     => 'Tahun kendaraan tidak valid.',
            'year.max'     => 'Tahun kendaraan tidak valid.',
        ];
    }

    /**
     * Isi data vehicle secara aman.
     *
     * Initial hour meter tetap disimpan di initial_kpi.
     * current_hour_meter dipakai sebagai kondisi terbaru.
     */
    private function fillVehicle(Vehicle $vehicle, array $data, bool $isCreate = false): void
    {
        if (array_key_exists('equipment_name', $data)) {
            $vehicle->equipment_name = $data['equipment_name'];
        }

        if (array_key_exists('plate_number', $data)) {
            $vehicle->plate_number = $data['plate_number'];
        }

        if (array_key_exists('serial_number', $data)) {
            $vehicle->serial_number = $data['serial_number'];
        }

        if (array_key_exists('initial_kpi', $data)) {
            $vehicle->initial_kpi = $data['initial_kpi'];
        }

        /**
         * Logika HM terbaru:
         * - Saat create kendaraan, kalau current_hour_meter tidak dikirim,
         *   maka current_hour_meter = initial_kpi.
         * - Saat update kendaraan, current_hour_meter hanya berubah kalau
         *   request memang mengirim current_hour_meter / latest_hour_meter /
         *   final_hour_meter / hour_meter_terbaru.
         * - Update otomatis dari teknisi tetap aman karena teknisi update
         *   langsung ke vehicles.current_hour_meter melalui ServiceJobController.
         */
        if (array_key_exists('current_hour_meter', $data)) {
            $this->applyCurrentHourMeter($vehicle, $data['current_hour_meter']);
        } elseif ($isCreate && array_key_exists('initial_kpi', $data)) {
            $this->applyCurrentHourMeter($vehicle, $data['initial_kpi']);
        }

        if (array_key_exists('brand', $data)) {
            $vehicle->brand = $data['brand'];
        }

        if (array_key_exists('model', $data)) {
            $vehicle->model = $data['model'];
        }

        if (array_key_exists('year', $data)) {
            $vehicle->year = $data['year'];
        }

        if (array_key_exists('target_availability', $data)) {
            $this->safeSet($vehicle, 'target_availability', $data['target_availability']);
            $this->safeSet($vehicle, 'target_ma', $data['target_availability']);
        }

        if (array_key_exists('current_ma', $data)) {
            $this->applyCurrentMa($vehicle, $data['current_ma']);
        }

        if (array_key_exists('status', $data)) {
            $this->safeSet($vehicle, 'status', $data['status'] ?: 'active');
        } elseif ($isCreate) {
            $this->safeSet($vehicle, 'status', 'active');
        }
    }

    /**
     * Terapkan HM terbaru ke semua kolom alias yang tersedia.
     */
    private function applyCurrentHourMeter(Vehicle $vehicle, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->safeSet($vehicle, 'current_hour_meter', $value);
        $this->safeSet($vehicle, 'latest_hour_meter', $value);
        $this->safeSet($vehicle, 'final_hour_meter', $value);
    }

    /**
     * Terapkan MA terbaru ke semua kolom alias yang tersedia.
     */
    private function applyCurrentMa(Vehicle $vehicle, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->safeSet($vehicle, 'current_ma', $value);
        $this->safeSet($vehicle, 'ma', $value);
        $this->safeSet($vehicle, 'mechanical_availability', $value);
    }

    /**
     * Format response agar cocok dengan VehiclesPage.jsx terbaru.
     */
    private function vehicleResponse(Vehicle $vehicle): array
    {
        $initialHourMeter = $vehicle->initial_kpi ?? 0;

        $targetAvailability = $this->firstValue([
            $this->hasColumn('vehicles', 'target_availability') ? $vehicle->target_availability : null,
            $this->hasColumn('vehicles', 'target_ma') ? $vehicle->target_ma : null,
            90,
        ]);

        $currentHourMeter = $this->getCurrentHourMeterValue($vehicle);

        $currentMa = $this->firstValue([
            $this->hasColumn('vehicles', 'current_ma') ? $vehicle->current_ma : null,
            $this->hasColumn('vehicles', 'ma') ? $vehicle->ma : null,
            $this->hasColumn('vehicles', 'mechanical_availability') ? $vehicle->mechanical_availability : null,
            null,
        ]);

        return [
            'id' => $vehicle->id,

            'equipment_name' => $vehicle->equipment_name,
            'plate_number' => $vehicle->plate_number,
            'serial_number' => $vehicle->serial_number,

            /**
             * Field lama tetap dikirim agar kompatibel dengan fitur lama.
             */
            'initial_kpi' => $vehicle->initial_kpi,

            /**
             * Field baru untuk frontend.
             * Di database tetap initial_kpi.
             */
            'initial_hour_meter' => $initialHourMeter,

            /**
             * HM terbaru dari vehicles.
             * Ini yang harus dipakai VehicleAssignmentPage admin dan
             * damage_report_page driver pada Assigned Unit.
             */
            'current_hour_meter' => $currentHourMeter,
            'latest_hour_meter' => $this->firstValue([
                $this->hasColumn('vehicles', 'latest_hour_meter') ? $vehicle->latest_hour_meter : null,
                $currentHourMeter,
            ]),
            'final_hour_meter' => $this->firstValue([
                $this->hasColumn('vehicles', 'final_hour_meter') ? $vehicle->final_hour_meter : null,
                $currentHourMeter,
            ]),
            'hour_meter_terbaru' => $currentHourMeter,

            /**
             * Target dan hasil Mechanical Availability.
             */
            'target_availability' => $targetAvailability,
            'target_ma' => $targetAvailability,
            'current_ma' => $currentMa,
            'ma' => $currentMa,
            'mechanical_availability' => $currentMa,

            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,

            'status' => $this->hasColumn('vehicles', 'status')
                ? ($vehicle->status ?? 'active')
                : 'active',

            'last_repair_at' => $this->hasColumn('vehicles', 'last_repair_at')
                ? $vehicle->last_repair_at
                : null,

            'last_maintenance_at' => $this->hasColumn('vehicles', 'last_maintenance_at')
                ? $vehicle->last_maintenance_at
                : null,

            'created_at' => $vehicle->created_at,
            'updated_at' => $vehicle->updated_at,
        ];
    }

    /**
     * Ambil HM terbaru dari vehicle.
     */
    private function getCurrentHourMeterValue(Vehicle $vehicle): mixed
    {
        return $this->firstValue([
            $this->hasColumn('vehicles', 'current_hour_meter') ? $vehicle->current_hour_meter : null,
            $this->hasColumn('vehicles', 'latest_hour_meter') ? $vehicle->latest_hour_meter : null,
            $this->hasColumn('vehicles', 'final_hour_meter') ? $vehicle->final_hour_meter : null,
            $vehicle->initial_kpi ?? 0,
        ]);
    }

    /**
     * Publish event realtime ke Node.
     * Jika realtime gagal, proses utama tetap berhasil.
     */
    private function publishRealtimeEvent(string $event, array $data): void
    {
        try {
            $realtimeUrl = config('services.realtime.url');
            $serviceKey = config('services.realtime.key');

            if (!$realtimeUrl || !$serviceKey) {
                return;
            }

            Http::withHeaders([
                'x-service-key' => $serviceKey,
            ])->post(
                rtrim($realtimeUrl, '/') . '/events/publish',
                [
                    'event' => $event,
                    'channels' => ['admin'],
                    'data' => $data,
                ]
            );
        } catch (\Throwable $e) {
            logger()->error('Realtime vehicle event failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set kolom hanya kalau kolomnya ada.
     */
    private function safeSet(Vehicle $vehicle, string $column, mixed $value): void
    {
        if ($this->hasColumn($vehicle->getTable(), $column)) {
            $vehicle->{$column} = $value;
        }
    }

    /**
     * Ambil value pertama yang tidak null / tidak kosong.
     */
    private function firstValue(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Helper cek kolom aman.
     */
    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
