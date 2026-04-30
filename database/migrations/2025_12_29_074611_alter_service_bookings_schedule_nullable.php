<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            $table->dateTime('scheduled_at')->nullable()->change();
            $table->dateTime('estimated_finish_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            $table->dateTime('scheduled_at')->nullable(false)->change();
            $table->dateTime('estimated_finish_at')->nullable(false)->change();
        });
    }
};
