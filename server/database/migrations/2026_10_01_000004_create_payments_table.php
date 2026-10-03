<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收退款流水：正数为收款，负数为退款。
 * business_date 为实际入账营业日（钱实际到账的日子）；
 * 若自然归属日已封账，则落到当前开放营业日，并自动生成
 * late_payment 调整单关联原营业日（见 adjustments 表）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->string('method', 16); // cash | card
            $table->bigInteger('amount_cents'); // 正=收款 负=退款
            $table->date('business_date')->index();
            $table->timestamp('occurred_at'); // 钱实际收付时间
            $table->string('receipt_no', 40)->unique();
            $table->timestamps();

            $table->index(['folio_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
