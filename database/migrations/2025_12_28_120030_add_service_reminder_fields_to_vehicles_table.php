<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('vehicles', function (Blueprint $table) {
      $table->dateTime('next_service_at')->nullable()->after('plate_number');
      $table->boolean('reminder_enabled')->default(false)->after('next_service_at');
      $table->unsignedInteger('reminder_days_before')->default(3)->after('reminder_enabled');
    });
  }

  public function down(): void
  {
    Schema::table('vehicles', function (Blueprint $table) {
      $table->dropColumn(['next_service_at','reminder_enabled','reminder_days_before']);
    });
  }
};
