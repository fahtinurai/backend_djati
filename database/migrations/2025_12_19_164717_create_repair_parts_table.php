<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('repair_parts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('repair_id')->constrained('repairs')->cascadeOnDelete();
    $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
    $table->integer('qty');
    $table->timestamps();
    $table->unique(['repair_id','part_id']);
    });
  }

  public function down(): void {
    Schema::dropIfExists('repair_parts');
  }
};
