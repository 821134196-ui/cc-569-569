<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\Folio;
use App\Services\BusinessDayResolver;
use App\Services\ChargeService;
use App\Services\FolioService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 营业日归属规则：跨午夜入住、延迟退房、支付回执晚到。
 */
class BusinessDayAttributionTest extends TestCase
{
    use RefreshDatabase;

    private BusinessDayResolver $resolver;
    private FolioService $folios;
    private PaymentService $payments;
    private ChargeService $charges;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-02 09:00:00');
        $this->resolver = app(BusinessDayResolver::class);
        $this->folios = app(FolioService::class);
        $this->payments = app(PaymentService::class);
        $this->charges = app(ChargeService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_late_evening_checkin_belongs_to_same_business_day(): void
    {
        $folio = $this->folios->checkIn('张三', '301', CarbonImmutable::parse('2026-10-01 23:50'), 1, 38800);

        $item = $folio->items()->sole();
        $this->assertSame('2026-10-01', $item->business_date->toDateString());
    }

    public function test_after_midnight_checkin_belongs_to_previous_business_day(): void
    {
        // 凌晨 00:30 入住：房费归"昨夜"营业日 2026-10-01
        $folio = $this->folios->checkIn('李四', '302', CarbonImmutable::parse('2026-10-02 00:30'), 1, 45800);

        $item = $folio->items()->sole();
        $this->assertSame('2026-10-01', $item->business_date->toDateString());
    }

    public function test_checkin_after_rollover_hour_belongs_to_new_day(): void
    {
        // 早晨 06:00 后入住：归新营业日
        $folio = $this->folios->checkIn('王五', '303', CarbonImmutable::parse('2026-10-02 06:00'), 1, 38800);

        $item = $folio->items()->sole();
        $this->assertSame('2026-10-02', $item->business_date->toDateString());
    }

    public function test_second_night_room_charge_belongs_to_second_business_day(): void
    {
        $folio = $this->folios->checkIn('赵六', '401', CarbonImmutable::parse('2026-10-01 22:00'), 2, 40000);

        $day2 = $this->resolver->ensureOpenDay('2026-10-02');
        $this->folios->postDueRoomCharges($day2);

        $dates = $folio->items()->orderBy('id')->pluck('business_date')
            ->map(fn ($d) => $d->toDateString())->all();
        $this->assertSame(['2026-10-01', '2026-10-02'], $dates);
    }

    public function test_late_payment_lands_on_open_day_with_adjustment_when_natural_day_closed(): void
    {
        // 2026-10-01 营业并封账
        $folio = $this->folios->checkIn('李华', '302', CarbonImmutable::parse('2026-10-01 23:50'), 1, 45800);
        $this->payments->record($folio, 'card', 20000, CarbonImmutable::parse('2026-10-01 23:52'));
        $day1 = BusinessDay::where('date', '2026-10-01')->sole();
        app(\App\Services\NightAuditService::class)->checkFolio($day1, $folio, '张夜审');
        $snapshot = app(\App\Services\NightAuditService::class)->close($day1, '张夜审');
        $this->assertSame(20000, $snapshot->cash_total_cents + $snapshot->card_total_cents);

        // 支付回执晚到：实际发生于 10-01 23:58（自然归属日已封账）
        $payment = $this->payments->record($folio, 'card', 25800, CarbonImmutable::parse('2026-10-01 23:58'));

        // 收款落到当前开放营业日 10-02，而非改写昨日
        $this->assertSame('2026-10-02', $payment->business_date->toDateString());

        // 自动生成迟到收款调整单，关联原营业日与该笔收款
        $adjustment = Adjustment::where('original_payment_id', $payment->id)->sole();
        $this->assertSame(Adjustment::KIND_LATE_PAYMENT, $adjustment->kind);
        $this->assertSame('2026-10-02', $adjustment->business_date->toDateString());
        $this->assertSame('2026-10-01', $adjustment->original_business_date->toDateString());
        $this->assertSame(25800, $adjustment->amount_cents);
        $this->assertFalse($adjustment->affects_revenue);

        // 昨日快照保持不动
        $this->assertSame(20000, $snapshot->refresh()->card_total_cents);
        $this->assertSame(0, BusinessDay::where('date', '2026-10-01')->sole()->snapshot->cash_total_cents);
    }

    public function test_late_checkout_charge_after_close_lands_on_open_day_with_adjustment(): void
    {
        $folio = $this->folios->checkIn('王小明', '301', CarbonImmutable::parse('2026-10-01 15:00'), 1, 38800);
        $day1 = BusinessDay::where('date', '2026-10-01')->sole();
        app(\App\Services\NightAuditService::class)->checkFolio($day1, $folio, '张夜审');
        app(\App\Services\NightAuditService::class)->close($day1, '张夜审');

        // 延迟退房费：实际发生 10-01 18:30，次日才补录
        $item = $this->charges->postItem(
            $folio, 'room_charge', '延迟退房补收', 19400,
            CarbonImmutable::parse('2026-10-01 18:30'), '前台'
        );

        $this->assertSame('2026-10-02', $item->business_date->toDateString());
        $adjustment = Adjustment::where('original_item_id', $item->id)->sole();
        $this->assertSame(Adjustment::KIND_LATE_CHARGE, $adjustment->kind);
        $this->assertSame('2026-10-01', $adjustment->original_business_date->toDateString());
    }

    public function test_closed_day_is_never_reopened_by_posting(): void
    {
        $day = $this->resolver->ensureOpenDay('2026-10-01');
        $day->update(['status' => BusinessDay::STATUS_CLOSED]);

        $folio = $this->folios->checkIn('测试', '501', CarbonImmutable::parse('2026-10-01 23:00'), 1, 10000);

        // 房费不会落回已封账的 10-01
        $this->assertSame('2026-10-02', $folio->items()->sole()->business_date->toDateString());
        $this->assertSame(BusinessDay::STATUS_CLOSED, $day->refresh()->status);
    }
}
