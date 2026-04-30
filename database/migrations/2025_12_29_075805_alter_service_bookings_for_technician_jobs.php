<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {

            // =========================
            // JADWAL (ADMIN)
            // =========================
            $table->dateTime('scheduled_at')->nullable()->change();
            $table->dateTime('estimated_finish_at')->nullable()->change();

            // =========================
            // STATUS (DRIVER → ADMIN → TEKNISI)
            // =========================
            $table->string('status', 20)
                ->default('requested')
                ->change();

            // =========================
            // OPTIMISASI QUERY TEKNISI
            // =========================
            $table->index('status');
            $table->index('scheduled_at');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {

            // rollback index
            $table->dropIndex(['status']);
            $table->dropIndex(['scheduled_at']);
            $table->dropIndex(['status', 'scheduled_at']);

            // rollback kolom (opsional)
            $table->dateTime('scheduled_at')->nullable(false)->change();
            $table->dateTime('estimated_finish_at')->nullable(false)->change();
        });
    }
};
