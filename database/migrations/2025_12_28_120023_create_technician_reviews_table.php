<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('technician_reviews', function (Blueprint $table) {
      $table->id();

      // Melekat ke laporan kerusakan
      $table->unsignedBigInteger('damage_report_id')->unique();

      // siapa yang menilai
      $table->unsignedBigInteger('driver_id');

      // teknisi yang dinilai (diisi dari latestTechnicianResponse saat controller store)
      $table->unsignedBigInteger('technician_id');

      $table->unsignedTinyInteger('rating'); // 1..5
      $table->text('review')->nullable();

      // opsional kalau mau eksplisit waktu submit review
      $table->dateTime('reviewed_at')->nullable();

      $table->timestamps();

      // index untuk query cepat
      $table->index('technician_id');
      $table->index('driver_id');

      // FK
      $table->foreign('damage_report_id')
            ->references('id')->on('damage_reports')
            ->cascadeOnDelete();

      $table->foreign('driver_id')
            ->references('id')->on('users')
            ->cascadeOnDelete();

      $table->foreign('technician_id')
            ->references('id')->on('users')
            ->cascadeOnDelete();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('technician_reviews');
  }
};
