<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
        Schema::create('repairs', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_plate'); 
            $table->string('technician')->nullable(); 
            $table->text('action')->nullable();
            $table->decimal('cost', 14, 2)->default(0);
            $table->date('date')->nullable();
            $table->boolean('finalized')->default(false);
            $table->foreignId('damage_report_id')->nullable()->constrained('damage_reports')->nullOnDelete();

            $table->timestamps();
        });
  }

  public function down(): void {
    Schema::dropIfExists('repairs');
  }
};
