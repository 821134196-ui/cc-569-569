<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 调整单：封账后一切迟到收款 / 房费更正 / 加购更正的唯一入口。
 * 只增不改（append-only），是差额核对的依据。
 *
 * - business_date          调整落账的营业日（必须是开放日，计入该日汇总）
 * - original_business_date 原交易归属的营业日（通常是已封账日）
 * - original_item_id / original_payment_id 指向原交易，财务可逐笔追溯
 * - affects_revenue        是否计入落账日营收（迟到收款只是"钱的归属说明"，
 *                          现金已计入当日收款，故为 false，避免重复计数）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 40)->unique();
            $table->foreignId('folio_id')->constrained();
            $table->date('business_date')->index();
            $table->date('original_business_date')->index();
            $table->string('kind', 24); // late_payment | room_correction | addon_correction | late_checkout
            $table->bigInteger('amount_cents'); // 带符号
            $table->boolean('affects_revenue')->default(true);
            $table->string('reason', 255);
            $table->foreignId('original_item_id')->nullable()->constrained('folio_items');
            $table->foreignId('original_payment_id')->nullable()->constrained('payments');
            $table->string('created_by', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustments');
    }
};
