<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'hour_meter')) {
                $table->decimal('hour_meter', 12, 2)->default(0)->after('plate_number');
            }

            if (!Schema::hasColumn('vehicles', 'fuel_consumption_liters')) {
                $table->decimal('fuel_consumption_liters', 12, 2)->default(0)->after('hour_meter');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'fuel_consumption_liters')) {
                $table->dropColumn('fuel_consumption_liters');
            }

            if (Schema::hasColumn('vehicles', 'hour_meter')) {
                $table->dropColumn('hour_meter');
            }
        });
    }
};