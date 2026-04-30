<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
        Schema::create('stock_movements', function (Blueprint $table) {
        $table->id();
        $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
        $table->enum('type', ['IN', 'OUT']);
        $table->integer('qty');
        $table->date('date')->nullable();
        $table->string('note')->nullable();
        $table->string('ref')->nullable();
        $table->timestamps();
    });
  }

  public function down(): void {
    Schema::dropIfExists('stock_movements');
  }
};
