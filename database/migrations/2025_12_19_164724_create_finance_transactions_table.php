<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
            Schema::create('finance_transactions', function (Blueprint $table) {
                $table->id();
                $table->enum('type', ['income', 'expense']);
                $table->string('category');
                $table->decimal('amount', 14, 2);
                $table->date('date');
                $table->string('note')->nullable();

                $table->enum('source', ['manual', 'repair', 'inventory'])->default('manual');
                $table->string('ref')->nullable();  
                $table->boolean('locked')->default(false); 
                $table->integer('qty')->nullable();
                $table->decimal('unit_price', 14, 2)->nullable();
                $table->json('meta')->nullable();

                $table->timestamps();
            });
  }

  public function down(): void {
    Schema::dropIfExists('finance_transactions');
  }
};
