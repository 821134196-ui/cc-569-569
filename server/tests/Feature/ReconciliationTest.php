<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\FolioItem;
use App\Services\AdjustmentService;
use App\Services\BusinessDayResolver;
use App\Services\FolioService;
use App\Services\NightAuditService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 封账后的差额核对：调整单可追溯原交易，汇总与调整流水对得上。
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private NightAuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-02 09:00:00');
        $this->audit = app(NightAuditService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * 搭场景：10-01 营业（房费 458 + 已收 200）并封账；
     * 随后发生迟到收款 258 与房费更正 -30，均落到 10-02。
     */
    private function makeScenario(): array
    {
        $folios = app(FolioService::class);
        $folio = $folios->checkIn('李华', '302', CarbonImmutable::parse('2026-10-01 23:50'), 1, 45800);
        app(PaymentService::class)->record($folio, 'card', 20000, CarbonImmutable::parse('2026-10-01 23:52'));

        $day1 = BusinessDay::where('date', '2026-10-01')->sole();
        $this->audit->checkFolio($day1, $folio, '张夜审');
        $snapshot = $this->audit->close($day1, '张夜审');

        // 迟到收款 258（实际 10-01 23:58 发生）
        $latePayment = app(PaymentService::class)->record($folio, 'card', 25800, CarbonImmutable::parse('2026-10-01 23:58'));

        // 房费更正 -30（房价录错）
        $roomItem = $folio->items()->where('kind', FolioItem::KIND_ROOM)->sole();
        $correction = app(AdjustmentService::class)->create(
            $folio, Adjustment::KIND_ROOM_CORRECTION, -3000, '房价录错，应为 428', $roomItem->id, '财务'
        );

        return [$folio, $day1->refresh(), $snapshot, $latePayment, $correction];
    }

    public function test_finance_can_trace_adjustment_to_original_transaction(): void
    {
        [$folio, $day1, $snapshot, $latePayment, $correction] = $this->makeScenario();

        // 迟到收款调整 → 原支付流水
        $lateAdj = Adjustment::where('kind', Adjustment::KIND_LATE_PAYMENT)->sole();
        $this->assertSame($latePayment->id, $lateAdj->original_payment_id);
        $this->assertSame(25800, $lateAdj->originalPayment->amount_cents);
        $this->assertSame($folio->id, $lateAdj->folio_id);

        // 房费更正调整 → 原房费账目
        $this->assertSame($folio->items()->sole()->id, $correction->original_item_id);
        $this->assertSame(45800, $correction->originalItem->amount_cents);
        $this->assertSame('2026-10-01', $correction->original_business_date->toDateString());
    }

    public function test_reconciliation_ties_snapshot_to_adjustment_ledger(): void
    {
        [, $day1, $snapshot] = $this->makeScenario();

        $recon = $this->audit->reconciliation($day1);

        // 快照数字不变
        $this->assertSame(45800, $snapshot->refresh()->room_total_cents);
        $this->assertSame(20000, $snapshot->card_total_cents);

        // 差额 = 后续营收性调整之和（-30）；迟到收款为备忘（钱已计入 10-02）
        $this->assertSame(-3000, $recon['revenue_delta_cents']);
        $this->assertSame(25800, $recon['memo_delta_cents']);
        $this->assertSame(45800 - 3000, $recon['revised_charge_total_cents']);

        // 关联 10-01 的后续调整共 2 条，逐笔可追
        $this->assertCount(2, $recon['later_adjustments']);
    }

    public function test_landing_day_summary_includes_adjustments(): void
    {
        $this->makeScenario();

        $day2 = BusinessDay::where('date', '2026-10-02')->sole();
        $summary = $this->audit->summary($day2);

        // 10-02 汇总：现金/卡含迟到收款 258，营收性调整 -30
        $this->assertSame(25800, $summary['totals']['card_cents']);
        $this->assertSame(-3000, $summary['totals']['adjustment_revenue_cents']);
        $this->assertSame(25800, $summary['totals']['adjustment_memo_cents']);

        // 汇总与调整流水对账：逐笔之和 = 汇总口径
        $ledgerSum = Adjustment::where('business_date', '2026-10-02')->where('affects_revenue', true)->sum('amount_cents');
        $this->assertSame($summary['totals']['adjustment_revenue_cents'], (int) $ledgerSum);
    }

    public function test_day_detail_endpoint_exposes_reconciliation(): void
    {
        $this->makeScenario();

        $response = $this->getJson('/api/days/2026-10-01');
        $response->assertOk()
            ->assertJsonPath('day.status', 'closed')
            ->assertJsonPath('snapshot.room_total_cents', 45800)
            ->assertJsonPath('reconciliation.revenue_delta_cents', -3000)
            ->assertJsonCount(2, 'later_adjustments');

        // 快照中的金额未被后续调整改写
        $this->assertSame(45800, BusinessDay::where('date', '2026-10-01')->sole()->snapshot->room_total_cents);
    }

    public function test_closed_day_totals_remain_consistent_after_second_day_close(): void
    {
        [$folio] = $this->makeScenario();

        // 10-02 也完成夜审封账
        $day2 = BusinessDay::where('date', '2026-10-02')->sole();
        $summary = $this->audit->summary($day2);
        foreach ($summary['folios'] as $activity) {
            $this->audit->checkFolio($day2, $activity['folio'], '李夜审');
        }
        $snapshot2 = $this->audit->close($day2, '李夜审');

        // 10-02 快照：卡 258（迟到收款），营收调整 -30
        $this->assertSame(25800, $snapshot2->card_total_cents);
        $this->assertSame(-3000, $snapshot2->adjustment_total_cents);

        // 两日合计应收 = 458 - 30 = 428，与房单实际一致
        $day1Snapshot = BusinessDay::where('date', '2026-10-01')->sole()->snapshot;
        $total = $day1Snapshot->net_total_cents + $snapshot2->net_total_cents;
        $this->assertSame(42800, $total);
    }
}
