<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('service_bookings', function (Blueprint $table) {
      $table->id();

      // 🔗 inti relasi (SATU-SATUNYA FK utama)
      $table->unsignedBigInteger('damage_report_id')->unique();

      // waktu
      $table->dateTime('requested_at');               // driver request
      $table->dateTime('scheduled_at')->nullable();   // admin set
      $table->dateTime('estimated_finish_at')->nullable();

      // status booking
      $table->enum('status', [
        'requested',
        'approved',
        'rescheduled',
        'canceled',
        'done'
      ])->default('requested');

      // catatan
      $table->text('note_driver')->nullable();
      $table->text('note_admin')->nullable();

      $table->timestamps();

      $table->foreign('damage_report_id')
            ->references('id')
            ->on('damage_reports')
            ->cascadeOnDelete();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('service_bookings');
  }
};
