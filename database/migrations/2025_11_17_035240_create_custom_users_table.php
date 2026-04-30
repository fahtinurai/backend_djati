<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->unique();
        $table->string('login_key'); // idealnya di-hash, tapi sekarang plain dulu
        $table->enum('role', ['admin', 'driver', 'teknisi']);
        $table->boolean('is_active')->default(true);

        // opsional: kalau mau tetap punya password/email untuk future
        $table->string('email')->nullable()->unique();
        $table->string('password')->nullable();

        $table->rememberToken();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_users');
    }
};
