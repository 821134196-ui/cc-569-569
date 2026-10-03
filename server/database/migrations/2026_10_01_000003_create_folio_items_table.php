<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 房单账目项：房费（room_charge，按住宿夜生成）与加购项目（addon）。
 * business_date 为归属营业日；occurred_at 为实际发生时间，二者共同
 * 决定跨午夜场景的归属。金额一律为整数分。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // room_charge | addon
            $table->string('description', 128);
            $table->bigInteger('amount_cents'); // 正数，冲正走调整单
            $table->date('business_date')->index();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['folio_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_items');
    }
};
