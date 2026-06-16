<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE technician_part_usages
            MODIFY status ENUM('requested', 'approved', 'rejected')
            NOT NULL DEFAULT 'requested'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE technician_part_usages
            MODIFY status ENUM('requested', 'approved')
            NOT NULL DEFAULT 'requested'
        ");
    }
};