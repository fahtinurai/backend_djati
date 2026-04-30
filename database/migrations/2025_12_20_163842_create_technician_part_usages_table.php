<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('technician_part_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('technician_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('damage_report_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('part_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->integer('qty');

            // request -> approved
            $table->enum('status', ['requested', 'approved'])
                ->default('requested');

            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_part_usages');
    }
};
