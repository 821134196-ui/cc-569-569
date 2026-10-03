<?php

namespace Database\Seeders;

use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\FolioItem;
use App\Services\BusinessDayResolver;
use App\Services\ChargeService;
use App\Services\FolioService;
use App\Services\NightAuditService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * 演示数据：
 *  - 昨日营业日 D-1：正常营业后封账（含跨午夜前入住的房单）
 *  - 今日营业日 D  ：跨午夜入住、晚到支付回执、延迟退房补收等
 *    归属难题全部落在今日，供夜审页核对与封账。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Folio::count() > 0) {
            return; // 幂等：已有数据则跳过
        }

        $resolver = app(BusinessDayResolver::class);
        $folios = app(FolioService::class);
        $charges = app(ChargeService::class);
        $payments = app(PaymentService::class);
        $audit = app(NightAuditService::class);

        // 当前营业日 D = 现在的自然归属营业日
        $D = $resolver->naturalDate(CarbonImmutable::now());
        $d1 = $D->subDay();

        // ---------- 昨日 D-1 的营业 ----------
        // 王小明：昨日 15:00 入住一晚，当日现金结清（延迟退房费次日才补录）
        $a = $folios->checkIn('王小明', '301', $d1->setTime(15, 0), 1, 38800, '前台');
        $payments->record($a, 'cash', 38800, $d1->setTime(15, 5), '前台');
        $a->update(['status' => Folio::STATUS_CHECKED_OUT, 'actual_check_out_at' => $d1->setTime(18, 30)]);

        // 李华：昨日 23:50 入住（深夜），房费归 D-1；尾款回执次日才到
        $b = $folios->checkIn('李华', '302', $d1->setTime(23, 50), 1, 45800, '前台');
        $payments->record($b, 'card', 20000, $d1->setTime(23, 52), '前台');
        $b->update(['status' => Folio::STATUS_CHECKED_OUT, 'actual_check_out_at' => $D->setTime(11, 30)]);

        // 刘芳：昨日 22:00 入住两晚，押金现金；第二晚房费今日过账
        $d = $folios->checkIn('刘芳', '402', $d1->setTime(22, 0), 2, 42800, '前台');
        $payments->record($d, 'cash', 30000, $d1->setTime(22, 10), '前台');

        // 昨日夜审：过账当夜房费 → 全部核对 → 封账
        $day1 = BusinessDay::where('date', $d1->toDateString())->firstOrFail();
        $folios->postDueRoomCharges($day1, '张夜审');
        $summary = $audit->summary($day1);
        foreach ($summary['folios'] as $activity) {
            $audit->checkFolio($day1, $activity['folio'], '张夜审');
        }
        $audit->close($day1, '张夜审');

        // ---------- 今日 D 的营业（D-1 已封账） ----------
        // 张伟：今日凌晨 00:30 跨午夜入住 —— 自然归属 D-1 已封账，
        // 房费与押金落入今日并自动生成调整单
        $c = $folios->checkIn('张伟', '401', $D->setTime(0, 30), 1, 52000, '前台');
        $payments->record($c, 'cash', 20000, $D->setTime(0, 35), '前台');

        // 刘芳第二晚房费过账到今日
        $dayD = $resolver->currentOpenDay();
        $folios->postDueRoomCharges($dayD, '系统');

        // 王小明延迟退房补收（昨日 18:30 退房，费用今日才补录）→ 迟到入账
        $charges->postItem($a, FolioItem::KIND_ROOM, '延迟退房补收（半日房费）', 19400, $d1->setTime(18, 30), '前台');

        // 李华尾款支付回执晚到（昨日 23:58 刷卡，回执今日才到）→ 迟到收款
        $payments->record($b, 'card', 25800, $d1->setTime(23, 58), '银行清算');

        // 陈晨：今日 14:00 正常入住，加购商务午餐，银行卡结清
        $e = $folios->checkIn('陈晨', '403', $D->setTime(14, 0), 1, 36800, '前台');
        $charges->postItem($e, FolioItem::KIND_ADDON, '商务午餐', 8800, $D->setTime(12, 30), '餐饮部');
        $payments->record($e, 'card', 45600, $D->setTime(14, 20), '前台');

        // 刘芳今日迷你吧消费 + 一笔多收现金退款
        $charges->postItem($d, FolioItem::KIND_ADDON, '迷你吧消费', 4500, $D->setTime(16, 0), '客房部');
        $payments->record($d, 'cash', -2000, $D->setTime(16, 30), '前台');
    }
}
