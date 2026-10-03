<?php

namespace Tests\Feature;

use App\Domain\BusinessDayService;
use App\Domain\FolioService;
use App\Domain\MockPaymentGateway;
use App\Domain\NightAuditService;
use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\FolioItem;
use App\Models\PaymentReceipt;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 营业日归属规则检验：跨午夜入住、延迟退房、支付回执晚到。
 */
class BusinessDayAttributionTest extends TestCase
{
    private FolioService $folios;
    private MockPaymentGateway $gateway;
    private NightAuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folios = app(FolioService::class);
        $this->gateway = app(MockPaymentGateway::class);
        $this->audit = app(NightAuditService::class);
    }

    public function test_cross_midnight_checkin_room_charge_belongs_to_checkin_night(): void
    {
        // 10-03 为当前开放营业日
        $this->openDay('2026-10-03');

        // 客人 10-03 23:50 入住（跨午夜场景）
        $stay = $this->folios->checkIn('301', '张三', Carbon::parse('2026-10-03 23:50:00'));

        // 房费在 10-04 00:10 才过账（夜审过房费动作发生在午夜后）
        $item = $this->folios->postRoomCharge($stay, '2026-10-03', 58800, Carbon::parse('2026-10-04 00:10:00'));

        // 归属营业日 = 住宿夜开始日（10-03），而不是过账时间（10-04）
        $this->assertSame('2026-10-03', $item->business_date->toDateString());
        $this->assertSame('2026-10-04 00:10:00', $item->posted_at->format('Y-m-d H:i:s'));

        // 夜审汇总：10-03 的房费包含这笔，10-04 不包含
        $day3 = BusinessDay::where('date', '2026-10-03')->first();
        $summary3 = $this->audit->summary($day3);
        $this->assertSame(58800, $summary3['buckets']['room_charge_cents']);

        $this->openDay('2026-10-04');
        $day4 = BusinessDay::where('date', '2026-10-04')->first();
        $summary4 = $this->audit->summary($day4);
        $this->assertSame(0, $summary4['buckets']['room_charge_cents']);
    }

    public function test_late_checkout_fee_belongs_to_checkout_day(): void
    {
        $this->openDay('2026-10-03');
        $stay = $this->folios->checkIn('302', '李四', Carbon::parse('2026-10-03 20:00:00'));
        $this->folios->postRoomCharge($stay, '2026-10-03', 68800, Carbon::parse('2026-10-04 00:05:00'));

        // 封账 10-03，开启 10-04
        $day3 = BusinessDay::where('date', '2026-10-03')->first();
        $this->audit->verifyFolio($day3, $stay->folio, $this->auditor);
        $this->audit->close($day3, $this->auditor);

        // 10-04 14:00 延迟退房 → 延迟退房费归属 10-04（退房当天），不碰 10-03 的数字
        $this->folios->checkOut($stay, Carbon::parse('2026-10-04 14:00:00'), 34400);

        $fee = FolioItem::where('description', '延迟退房费')->sole();
        $this->assertSame('2026-10-04', $fee->business_date->toDateString());
        $this->assertSame(FolioItem::TYPE_ADDON, $fee->type);

        // 10-03 的快照数字没有被改动
        $snapshot = $day3->fresh()->snapshot;
        $this->assertSame(68800, $snapshot->room_charge_cents);
        $this->assertSame(0, $snapshot->addon_cents);
    }

    public function test_payment_receipt_arriving_while_day_open_settles_directly(): void
    {
        $this->openDay('2026-10-03');
        $stay = $this->folios->checkIn('303', '王五', Carbon::parse('2026-10-03 15:00:00'));

        // 刷卡 500 元，回执晚到（pending）
        $receipt = $this->gateway->pay($stay->folio, 50000, 'card', true, Carbon::parse('2026-10-03 23:40:00'));
        $this->assertSame(PaymentReceipt::STATUS_PENDING, $receipt->status);

        // 当天回执到达（营业日仍开放）：直接计入 10-03
        $result = $this->gateway->settlePending($this->frontdesk, null, Carbon::parse('2026-10-03 23:58:00'));

        $this->assertSame('direct', $result['settled'][0]['via']);
        $this->assertSame('2026-10-03', $result['settled'][0]['business_date']);
        $this->assertSame(FolioItem::STATUS_SETTLED, $receipt->folioItem->fresh()->status);
        $this->assertSame(0, Adjustment::count(), '营业日未封账时不应产生调整单');

        $summary = $this->audit->summary(BusinessDay::where('date', '2026-10-03')->first());
        $this->assertSame(-50000, $summary['buckets']['card_cents']);
    }

    public function test_late_payment_receipt_after_close_creates_adjustment_on_open_day(): void
    {
        $this->openDay('2026-10-03');
        $stay = $this->folios->checkIn('304', '赵六', Carbon::parse('2026-10-03 16:00:00'));
        $this->folios->postRoomCharge($stay, '2026-10-03', 58800, Carbon::parse('2026-10-03 18:00:00'));

        // 22:40 发起刷卡 700 元，回执晚到
        $receipt = $this->gateway->pay($stay->folio, 70000, 'card', true, Carbon::parse('2026-10-03 22:40:00'));

        // 夜审核对并封账 10-03（此时收款仍 pending，不计入当日数字）
        $day3 = BusinessDay::where('date', '2026-10-03')->first();
        $this->audit->verifyFolio($day3, $stay->folio, $this->auditor);
        $snapshot = $this->audit->close($day3, $this->auditor);
        $this->assertSame(0, $snapshot->card_cents, '未到账收款不计入快照');

        // 10-04 09:00 回执才到：原营业日已封账
        $result = $this->gateway->settlePending($this->frontdesk, null, Carbon::parse('2026-10-04 09:00:00'));

        // ① 生成迟到收款调整单，落在当前开放营业日 10-04
        $this->assertSame('adjustment', $result['settled'][0]['via']);
        $this->assertSame('2026-10-04', $result['settled'][0]['business_date']);

        $adjustment = Adjustment::sole();
        $this->assertSame(Adjustment::TYPE_LATE_PAYMENT, $adjustment->type);
        $this->assertSame(-70000, $adjustment->amount_cents);
        $this->assertSame('2026-10-04', $adjustment->business_date->toDateString());

        // ② 调整单关联原房账条目（财务可追到原交易）
        $this->assertSame($receipt->folio_item_id, $adjustment->original_item_id);
        $this->assertSame('2026-10-03', $adjustment->originalItem->business_date->toDateString());

        // ③ 原条目与 10-03 快照均未改动（不靠修改昨日数字消除差额）
        $this->assertSame(FolioItem::STATUS_PENDING, $receipt->folioItem->fresh()->status);
        $this->assertSame(0, $snapshot->fresh()->card_cents);
        $this->assertSame(58800, $snapshot->fresh()->net_cents);

        // ④ 房单余额正确：58800（房费）- 70000（调整收款）= -11200
        $this->assertSame(-11200, $stay->folio->fresh()->balanceCents());

        // ⑤ 10-04 的夜审汇总中，这笔钱出现在"调整"桶
        $day4 = BusinessDay::where('date', '2026-10-04')->first();
        $summary4 = $this->audit->summary($day4);
        $this->assertSame(-70000, $summary4['buckets']['adjustment_cents']);
        $this->assertSame(0, $summary4['buckets']['card_cents']);
    }
}
