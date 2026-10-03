<?php

namespace Tests\Feature;

use App\Domain\AdjustmentService;
use App\Domain\FolioService;
use App\Domain\MockPaymentGateway;
use App\Domain\NightAuditService;
use App\Models\Adjustment;
use App\Models\BusinessDay;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 封账后的差额核对：财务能从调整金额追到原交易，
 * 快照 + 调整流水 = 当前有效数字，且与房账调整条目合计对得上。
 */
class ReconciliationTest extends TestCase
{
    public function test_finance_can_trace_adjustments_and_totals_reconcile(): void
    {
        $folios = app(FolioService::class);
        $gateway = app(MockPaymentGateway::class);
        $audit = app(NightAuditService::class);

        // ---- 10-03：张三房费 588，现金 300；李四房费 688，刷卡 700 回执晚到 ----
        $this->openDay('2026-10-03');
        $stayA = $folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));
        $roomItem = $folios->postRoomCharge($stayA, '2026-10-03', 58800);
        $gateway->pay($stayA->folio, 30000, 'cash', false, Carbon::parse('2026-10-03 16:00:00'));

        $stayB = $folios->checkIn('302', '李四', Carbon::parse('2026-10-03 17:00:00'));
        $folios->postRoomCharge($stayB, '2026-10-03', 68800);
        $gateway->pay($stayB->folio, 70000, 'card', true, Carbon::parse('2026-10-03 22:00:00'));

        // ---- 夜审封账 10-03 ----
        $day3 = BusinessDay::where('date', '2026-10-03')->first();
        $audit->verifyFolio($day3, $stayA->folio, $this->auditor);
        $audit->verifyFolio($day3, $stayB->folio, $this->auditor);
        $snapshot = $audit->close($day3, $this->auditor);

        // 快照净额 = 58800 + 68800 - 30000 = 97600（700 元刷卡未到账，不计入）
        $this->assertSame(97600, $snapshot->net_cents);

        // ---- 10-04：回执晚到 + 房费更正 ----
        $gateway->settlePending($this->frontdesk, null, Carbon::parse('2026-10-04 09:00:00'));
        app(AdjustmentService::class)->create(
            folio: $stayA->folio,
            type: Adjustment::TYPE_ROOM_CHARGE_CORRECTION,
            amountCents: -5000,
            reasonCode: 'rate_error',
            reason: '房价录入错误，应为 538.00',
            creator: $this->finance,
            originalItem: $roomItem,
        );

        // ---- 财务核对报告 ----
        $report = $this->asUser($this->finance)->getJson('/api/finance/days/2026-10-03')->assertOk()->json();

        // 快照数字冻结不变（房费 58800 + 68800 = 127600）
        $this->assertSame(97600, $report['snapshot']['net_cents']);
        $this->assertSame(127600, $report['snapshot']['room_charge_cents']);

        // 两笔调整都关联到 10-03：迟到收款 -70000、房费更正 -5000
        $this->assertCount(2, $report['adjustments']);
        $this->assertSame(-75000, $report['adjustment_total_cents']);

        // 有效数字 = 快照 + 调整：房费 122600，收款 -100000
        $this->assertSame(122600, $report['effective']['room_charge_cents']);
        $this->assertSame(-100000, $report['effective']['payment_cents']);
        $this->assertSame(97600 - 75000, $report['effective_net_cents']);

        // 每笔调整都能追到原交易
        $latePayment = collect($report['adjustments'])->firstWhere('type', 'late_payment');
        $this->assertSame('2026-10-03', $latePayment['original_item']['business_date']);
        $this->assertSame(-70000, $latePayment['original_item']['amount_cents']);
        $correction = collect($report['adjustments'])->firstWhere('type', 'room_charge_correction');
        $this->assertSame($roomItem->id, $correction['original_item']['id']);

        $this->assertTrue($report['reconciled']);

        // ---- 全局流水对账：调整单合计 == 房账调整条目合计 ----
        $recon = $this->asUser($this->finance)->getJson('/api/finance/reconciliation')->assertOk()->json();
        $this->assertSame(2, $recon['adjustment_count']);
        $this->assertSame(-75000, $recon['adjustment_total_cents']);
        $this->assertSame(-75000, $recon['ledger_adjustment_total_cents']);
        $this->assertTrue($recon['totals_tied']);
        $this->assertTrue($recon['per_item_tied']);

        // ---- 快照未被修改 ----
        $this->assertSame(97600, $snapshot->fresh()->net_cents);
        $this->assertSame(127600, $snapshot->fresh()->room_charge_cents);
    }

    public function test_adjustment_api_requires_original_item_and_reason(): void
    {
        $folios = app(FolioService::class);
        $this->openDay('2026-10-03');
        $stay = $folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));

        $this->asUser($this->finance)->postJson('/api/adjustments', [
            'folio_id' => $stay->folio->id,
            'type' => 'room_charge_correction',
            'amount_cents' => -5000,
        ])->assertStatus(422); // 缺 original_item_id 和 reason

        $this->assertSame(0, Adjustment::count());
    }
}
