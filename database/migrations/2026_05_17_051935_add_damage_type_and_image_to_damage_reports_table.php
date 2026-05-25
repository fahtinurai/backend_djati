<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan kolom yang dipakai oleh DamageReportController@store.
     */
    public function up(): void
    {
        Schema::table('damage_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('damage_reports', 'damage_type')) {
                $table->string('damage_type')->nullable()->after('driver_id');
            }

            if (!Schema::hasColumn('damage_reports', 'image')) {
                $table->string('image')->nullable()->after('description');
            }

            if (!Schema::hasColumn('damage_reports', 'status')) {
                $table->string('status')->default('menunggu')->after('image');
            }
        });
    }

    /**
     * Rollback kolom tambahan.
     */
    public function down(): void
    {
        Schema::table('damage_reports', function (Blueprint $table) {
            if (Schema::hasColumn('damage_reports', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('damage_reports', 'image')) {
                $table->dropColumn('image');
            }

            if (Schema::hasColumn('damage_reports', 'damage_type')) {
                $table->dropColumn('damage_type');
            }
        });
    }
};