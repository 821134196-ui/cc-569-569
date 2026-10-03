<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 房单（folio）：一位客人一次住宿的账务载体。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->string('folio_no', 32)->unique();
            $table->string('guest_name', 64);
            $table->string('room_no', 16);
            $table->timestamp('check_in_at');
            $table->timestamp('planned_check_out_at');
            $table->timestamp('actual_check_out_at')->nullable();
            $table->unsignedSmallInteger('nights')->default(1);
            $table->bigInteger('room_rate_cents')->default(0);
            $table->unsignedSmallInteger('room_charges_posted')->default(0); // 已生成房费的住宿夜数
            $table->string('status', 16)->default('open'); // open | checked_out
            $table->timestamps();

            $table->index('check_in_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
