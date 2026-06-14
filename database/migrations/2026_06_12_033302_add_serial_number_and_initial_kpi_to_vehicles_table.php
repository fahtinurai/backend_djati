<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'serial_number')) {
                $table->string('serial_number')
                    ->nullable()
                    ->unique()
                    ->after('plate_number');
            }

            if (!Schema::hasColumn('vehicles', 'initial_kpi')) {
                $table->decimal('initial_kpi', 12, 2)
                    ->default(0)
                    ->after('serial_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'initial_kpi')) {
                $table->dropColumn('initial_kpi');
            }

            if (Schema::hasColumn('vehicles', 'serial_number')) {
                $table->dropUnique(['serial_number']);
                $table->dropColumn('serial_number');
            }
        });
    }
};