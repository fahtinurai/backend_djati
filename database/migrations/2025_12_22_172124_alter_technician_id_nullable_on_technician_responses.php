<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_responses', function (Blueprint $table) {
            // 🔑 jadikan technician_id boleh null
            $table->unsignedBigInteger('technician_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('technician_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('technician_id')->nullable(false)->change();
        });
    }
};
