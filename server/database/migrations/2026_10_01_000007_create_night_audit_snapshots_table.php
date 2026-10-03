<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 夜审快照：封账瞬间的营业日汇总，保存后不可修改、不可删除
 * （模型层抛异常保护）。payload 保存完整明细 JSON 供审计回放。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('night_audit_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_day_id')->unique()->constrained();
            $table->bigInteger('room_total_cents')->default(0);
            $table->bigInteger('addon_total_cents')->default(0);
            $table->bigInteger('cash_total_cents')->default(0);
            $table->bigInteger('card_total_cents')->default(0);
            $table->bigInteger('refund_total_cents')->default(0);
            $table->bigInteger('adjustment_total_cents')->default(0);
            $table->bigInteger('net_total_cents')->default(0);
            $table->unsignedInteger('folio_count')->default(0);
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create('snapshot_folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('night_audit_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folio_id')->constrained();
            $table->string('folio_no', 32);
            $table->string('guest_name', 64);
            $table->string('room_no', 16);
            $table->bigInteger('room_total_cents')->default(0);
            $table->bigInteger('addon_total_cents')->default(0);
            $table->bigInteger('payment_total_cents')->default(0);
            $table->bigInteger('refund_total_cents')->default(0);
            $table->bigInteger('balance_cents')->default(0);
            $table->json('payload'); // 封账时该房单的逐项明细
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_folios');
        Schema::dropIfExists('night_audit_snapshots');
    }
};
