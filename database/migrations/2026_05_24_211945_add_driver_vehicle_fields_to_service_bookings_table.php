<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('service_bookings', 'driver_id')) {
                $table->foreignId('driver_id')
                    ->nullable()
                    ->after('damage_report_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('service_bookings', 'vehicle_id')) {
                $table->foreignId('vehicle_id')
                    ->nullable()
                    ->after('driver_id')
                    ->constrained('vehicles')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('service_bookings', 'technician_id')) {
                $table->foreignId('technician_id')
                    ->nullable()
                    ->after('vehicle_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('service_bookings', 'requested_at')) {
                $table->timestamp('requested_at')->nullable()->after('technician_id');
            }

            if (!Schema::hasColumn('service_bookings', 'preferred_at')) {
                $table->timestamp('preferred_at')->nullable()->after('requested_at');
            }

            if (!Schema::hasColumn('service_bookings', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('preferred_at');
            }

            if (!Schema::hasColumn('service_bookings', 'estimated_finish_at')) {
                $table->timestamp('estimated_finish_at')->nullable()->after('scheduled_at');
            }

            if (!Schema::hasColumn('service_bookings', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('estimated_finish_at');
            }

            if (!Schema::hasColumn('service_bookings', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }

            if (!Schema::hasColumn('service_bookings', 'priority')) {
                $table->string('priority')->default('medium')->after('completed_at');
            }

            if (!Schema::hasColumn('service_bookings', 'note_driver')) {
                $table->text('note_driver')->nullable()->after('priority');
            }

            if (!Schema::hasColumn('service_bookings', 'note_admin')) {
                $table->text('note_admin')->nullable()->after('note_driver');
            }

            if (!Schema::hasColumn('service_bookings', 'note_technician')) {
                $table->text('note_technician')->nullable()->after('note_admin');
            }

            if (!Schema::hasColumn('service_bookings', 'mttr')) {
                $table->decimal('mttr', 10, 2)->nullable()->after('note_technician');
            }

            if (!Schema::hasColumn('service_bookings', 'mtbf')) {
                $table->decimal('mtbf', 10, 2)->nullable()->after('mttr');
            }

            if (!Schema::hasColumn('service_bookings', 'ma')) {
                $table->decimal('ma', 10, 1)->nullable()->after('mtbf');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            $columns = [
                'ma',
                'mtbf',
                'mttr',
                'note_technician',
                'note_admin',
                'note_driver',
                'priority',
                'completed_at',
                'started_at',
                'estimated_finish_at',
                'scheduled_at',
                'preferred_at',
                'requested_at',
                'technician_id',
                'vehicle_id',
                'driver_id',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('service_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};