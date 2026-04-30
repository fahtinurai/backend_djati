<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('cost_estimates', function (Blueprint $table) {
      $table->id();

      // inti: melekat ke laporan kerusakan
      $table->unsignedBigInteger('damage_report_id')->unique();

      // dibuat oleh teknisi
      $table->unsignedBigInteger('technician_id');

      // biaya (rupiah)
      $table->unsignedInteger('labor_cost')->default(0);
      $table->unsignedInteger('parts_cost')->default(0);
      $table->unsignedInteger('other_cost')->default(0);

      // total dihitung backend (labor+parts+other)
      $table->unsignedInteger('total_cost')->default(0);

      $table->text('note')->nullable();

      // workflow admin approval
      $table->enum('status', ['draft','submitted','approved','rejected'])->default('draft');
      $table->unsignedBigInteger('approved_by')->nullable();
      $table->dateTime('approved_at')->nullable();

      $table->timestamps();

      // indexes untuk list admin
      $table->index('technician_id');
      $table->index('status');

      // FK
      $table->foreign('damage_report_id')
            ->references('id')->on('damage_reports')
            ->cascadeOnDelete();

      $table->foreign('technician_id')
            ->references('id')->on('users')
            ->cascadeOnDelete();

      $table->foreign('approved_by')
            ->references('id')->on('users')
            ->nullOnDelete();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('cost_estimates');
  }
};
