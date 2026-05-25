<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_daily_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->cascadeOnDelete();

            $table->foreignId('driver_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('log_date');
            $table->string('shift')->nullable();

            $table->decimal('hour_meter_start', 12, 2);
            $table->decimal('hour_meter_end', 12, 2);
            $table->decimal('fuel_liters', 12, 2)->default(0);

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'driver_id']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_daily_logs');
    }
};