<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom KPI untuk hasil perhitungan teknisi.
     */
    public function up(): void
    {
        Schema::table('technician_responses', function (Blueprint $table) {
            if (!Schema::hasColumn('technician_responses', 'mttr')) {
                $table->decimal('mttr', 10, 2)->nullable()->after('note');
            }

            if (!Schema::hasColumn('technician_responses', 'mtbf')) {
                $table->decimal('mtbf', 10, 2)->nullable()->after('mttr');
            }

            if (!Schema::hasColumn('technician_responses', 'ma')) {
                $table->decimal('ma', 10, 2)->nullable()->after('mtbf');
            }
        });
    }

    /**
     * Hapus kolom KPI jika rollback.
     */
    public function down(): void
    {
        Schema::table('technician_responses', function (Blueprint $table) {
            if (Schema::hasColumn('technician_responses', 'ma')) {
                $table->dropColumn('ma');
            }

            if (Schema::hasColumn('technician_responses', 'mtbf')) {
                $table->dropColumn('mtbf');
            }

            if (Schema::hasColumn('technician_responses', 'mttr')) {
                $table->dropColumn('mttr');
            }
        });
    }
};