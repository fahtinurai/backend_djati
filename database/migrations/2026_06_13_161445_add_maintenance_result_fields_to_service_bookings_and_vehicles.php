<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Field ini disesuaikan dengan:
     * App\Http\Controllers\Api\Technician\ServiceJobController@complete
     */
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | SERVICE BOOKINGS
        |--------------------------------------------------------------------------
        |
        | Field di sini menyimpan data hasil input teknisi saat COMPLETE SERVICE:
        | - hour meter terbaru
        | - data mentah maintenance
        | - hasil hitung backend: MTTR, MTBF, MA
        |
        */
        Schema::table('service_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('service_bookings', 'started_at')) {
                $table->timestamp('started_at')->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'note_technician')) {
                $table->text('note_technician')->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'final_hour_meter')) {
                $table->decimal('final_hour_meter', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'current_hour_meter')) {
                $table->decimal('current_hour_meter', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'latest_hour_meter')) {
                $table->decimal('latest_hour_meter', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'total_repair_time')) {
                $table->decimal('total_repair_time', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'total_operational_time')) {
                $table->decimal('total_operational_time', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'failure_count')) {
                $table->integer('failure_count')->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'actual_operating_hours')) {
                $table->decimal('actual_operating_hours', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'breakdown_hours')) {
                $table->decimal('breakdown_hours', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'mttr')) {
                $table->decimal('mttr', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'mtbf')) {
                $table->decimal('mtbf', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('service_bookings', 'ma')) {
                $table->decimal('ma', 6, 2)->nullable();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | VEHICLES
        |--------------------------------------------------------------------------
        |
        | Field di sini menyimpan kondisi terbaru unit.
        | Initial hour meter tetap tidak diubah.
        |
        | PENTING:
        | Jangan pakai ->after('initial_hour_meter') karena tabel vehicles kamu
        | tidak punya kolom initial_hour_meter. Kolom awal kamu tetap initial_kpi.
        |
        */
        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'current_hour_meter')) {
                $table->decimal('current_hour_meter', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('vehicles', 'latest_hour_meter')) {
                $table->decimal('latest_hour_meter', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('vehicles', 'final_hour_meter')) {
                $table->decimal('final_hour_meter', 12, 2)->nullable();
            }

            if (!Schema::hasColumn('vehicles', 'current_ma')) {
                $table->decimal('current_ma', 6, 2)->nullable();
            }

            if (!Schema::hasColumn('vehicles', 'last_repair_at')) {
                $table->timestamp('last_repair_at')->nullable();
            }

            if (!Schema::hasColumn('vehicles', 'last_maintenance_at')) {
                $table->timestamp('last_maintenance_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            $columns = [
                'started_at',
                'completed_at',
                'note_technician',
                'final_hour_meter',
                'current_hour_meter',
                'latest_hour_meter',
                'total_repair_time',
                'total_operational_time',
                'failure_count',
                'actual_operating_hours',
                'breakdown_hours',
                'mttr',
                'mtbf',
                'ma',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('service_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $columns = [
                'current_hour_meter',
                'latest_hour_meter',
                'final_hour_meter',
                'current_ma',
                'last_repair_at',
                'last_maintenance_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('vehicles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};