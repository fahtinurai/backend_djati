<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('technician_reviews', function ($table) {
            $table->unique('damage_report_id', 'tech_reviews_damage_report_unique');
        });
    }

    public function down()
    {
        Schema::table('technician_reviews', function ($table) {
            $table->dropUnique('tech_reviews_damage_report_unique');
        });
    }

};
