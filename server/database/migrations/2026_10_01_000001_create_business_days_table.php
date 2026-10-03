<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 营业日：酒店账务按营业日归集，夜审封账后该日账务冻结。
 * date 唯一约束 + status 条件更新共同保证"只能封账一次"。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('status', 16)->default('open')->index(); // open | closed
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_days');
    }
};
