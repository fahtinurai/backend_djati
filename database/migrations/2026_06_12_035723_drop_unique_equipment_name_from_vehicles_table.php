<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Hapus unique index equipment_name
        |--------------------------------------------------------------------------
        | Nama unit/equipment seperti Excavator, Dump Truck, Bulldozer
        | boleh dipakai berkali-kali.
        |
        | Identitas unik kendaraan tetap menggunakan:
        | - plate_number
        | - serial_number
        |--------------------------------------------------------------------------
        */

        try {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropUnique('vehicles_equipment_name_unique');
            });
        } catch (\Throwable $e) {
            logger()->warning('Unique index equipment_name sudah tidak ada atau gagal dihapus.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        try {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->unique('equipment_name', 'vehicles_equipment_name_unique');
            });
        } catch (\Throwable $e) {
            logger()->warning('Gagal membuat ulang unique index equipment_name.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
};