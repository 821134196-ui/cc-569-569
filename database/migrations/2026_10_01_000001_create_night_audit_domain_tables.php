<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 营业日：夜审封账的对象。date 唯一，status open -> closed 单向流转。
        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('status', 10)->default('open'); // open | closed
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // 住宿（房态接口的本地模拟）：一次入住一条记录。
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->string('room_no', 20);
            $table->string('guest_name');
            $table->timestamp('check_in_at');
            $table->timestamp('check_out_at')->nullable();
            $table->string('status', 20)->default('in_house'); // in_house | checked_out
            $table->timestamps();
        });

        // 房单：一次住宿一本账。
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays');
            $table->string('folio_no', 30)->unique();
            $table->string('guest_name');
            $table->string('room_no', 20);
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamps();
        });

        // 房账条目：账务的唯一事实来源。金额单位为分，带符号：
        // 房费/加购为正，收款为负，退款为正（冲减已收）。
        // business_date 是交易归属的营业日，posted_at 是实际入账时间，
        // 两者可以不同（跨午夜入住的房费在次日过账，仍归属入住当晚）。
        Schema::create('folio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained('folios');
            $table->string('type', 20); // room_charge | addon | payment | refund | adjustment
            $table->string('description');
            $table->bigInteger('amount_cents'); // signed
            $table->date('business_date');
            $table->timestamp('posted_at');
            $table->string('status', 15)->default('settled'); // settled | pending | superseded
            $table->string('method', 10)->nullable(); // payments: cash | card
            $table->foreignId('adjustment_id')->nullable(); // set when this item was created by an adjustment
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['business_date', 'type', 'status']);
            $table->index('folio_id');
        });

        // 夜审员对房单的逐日核对记录：一本房单每个营业日核对一次。
        Schema::create('folio_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained('folios');
            $table->foreignId('business_day_id')->constrained('business_days');
            $table->foreignId('verified_by')->constrained('users');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(['folio_id', 'business_day_id']);
        });

        // 支付回执（支付网关的本地模拟）：回执可能晚到，晚到时触发调整单。
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 40)->unique();
            $table->foreignId('folio_item_id')->constrained('folio_items');
            $table->bigInteger('amount_cents'); // positive
            $table->string('status', 10)->default('pending'); // pending | settled
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        // 调整单：封账后的迟到收款 / 房费更正等，只增不改，
        // 落在当前开放营业日，并关联原房账条目与差额原因。
        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 30)->unique();
            $table->date('business_date'); // 调整入账的营业日（当前开放日）
            $table->foreignId('folio_id')->constrained('folios');
            $table->foreignId('original_item_id')->nullable()->constrained('folio_items');
            $table->string('type', 30); // late_payment | room_charge_correction | addon_correction | refund_correction
            $table->bigInteger('amount_cents'); // signed
            $table->string('reason_code', 40);
            $table->string('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['business_date']);
            $table->index(['folio_id']);
        });

        // 夜审快照头：封账时保存，不可修改。
        Schema::create('night_audit_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_day_id')->unique()->constrained('business_days');
            $table->date('business_date');
            $table->bigInteger('room_charge_cents')->default(0);
            $table->bigInteger('addon_cents')->default(0);
            $table->bigInteger('cash_cents')->default(0);      // 现金收款（负数=流入）
            $table->bigInteger('card_cents')->default(0);      // 非现金收款
            $table->bigInteger('refund_cents')->default(0);
            $table->bigInteger('adjustment_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);       // 全部已结算条目合计
            $table->unsignedInteger('folio_count')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->foreignId('closed_by')->constrained('users');
            $table->timestamp('closed_at');
            $table->timestamps();
        });

        // 快照-房单层：封账时每本有发生额的房单一行。
        Schema::create('snapshot_folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('night_audit_snapshots');
            $table->foreignId('folio_id')->constrained('folios');
            $table->string('folio_no', 30);
            $table->string('guest_name');
            $table->string('room_no', 20);
            $table->bigInteger('charge_cents')->default(0);
            $table->bigInteger('payment_cents')->default(0);
            $table->bigInteger('refund_cents')->default(0);
            $table->bigInteger('balance_cents')->default(0);
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // 快照-条目层：逐条复制，封账后原表如何变动都不影响快照。
        Schema::create('snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('night_audit_snapshots');
            $table->foreignId('folio_id')->constrained('folios');
            $table->foreignId('folio_item_id')->constrained('folio_items');
            $table->string('type', 20);
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->date('business_date');
            $table->timestamp('posted_at');
            $table->string('status', 15);
            $table->timestamps();

            $table->index('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_items');
        Schema::dropIfExists('snapshot_folios');
        Schema::dropIfExists('night_audit_snapshots');
        Schema::dropIfExists('adjustments');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('folio_verifications');
        Schema::dropIfExists('folio_items');
        Schema::dropIfExists('folios');
        Schema::dropIfExists('stays');
        Schema::dropIfExists('business_days');
    }
};
