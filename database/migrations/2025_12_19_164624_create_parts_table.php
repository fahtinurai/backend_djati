<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('parts', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('sku')->unique();
        $table->integer('stock')->default(0);
        $table->integer('min_stock')->default(0);
        $table->bigInteger('buy_price')->default(0); 
        $table->timestamps();
    });
  }

  public function down(): void {
    Schema::dropIfExists('parts');
  }
};
