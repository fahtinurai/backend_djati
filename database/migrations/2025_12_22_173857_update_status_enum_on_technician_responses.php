<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE technician_responses 
            MODIFY status ENUM(
                'proses',
                'butuh_followup_admin',
                'approved_followup_admin',
                'fatal',
                'selesai'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE technician_responses 
            MODIFY status ENUM(
                'proses',
                'butuh_followup_admin',
                'fatal',
                'selesai'
            ) NOT NULL
        ");
    }
};
