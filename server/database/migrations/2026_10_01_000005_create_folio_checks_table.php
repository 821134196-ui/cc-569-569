<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 夜审核对记录：某营业日内某房单已被哪位夜审员核对。
 * 封账前提：当日有账务活动的房单全部存在核对记录。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_day_id')->constrained()->cascadeOnDelete();
            $table->string('auditor', 64);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['folio_id', 'business_day_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_checks');
    }
};
