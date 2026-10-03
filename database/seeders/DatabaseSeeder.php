<?php

namespace Database\Seeders;

use App\Domain\BusinessDayService;
use App\Domain\FolioService;
use App\Domain\MockPaymentGateway;
use App\Domain\NightAuditService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * 演示初始数据：完整跑一遍"昨日营业 → 夜审封账 → 今日营业"，
 * 让系统启动后就有一个已封账营业日（含快照）和一个开放营业日。
 *
 * 场景要点：
 *  - 张三 23:50 跨午夜入住，房费次日 00:10 过账，仍归属入住当晚（昨日）。
 *  - 李四刷卡收款回执晚到（pending），留待演示封账后的迟到收款调整。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $auditorA = User::create(['name' => '夜审员甲', 'email' => 'auditor-a@hotel.local', 'password' => 'password', 'role' => User::ROLE_AUDITOR]);
        User::create(['name' => '夜审员乙', 'email' => 'auditor-b@hotel.local', 'password' => 'password', 'role' => User::ROLE_AUDITOR]);
        User::create(['name' => '前台小王', 'email' => 'frontdesk@hotel.local', 'password' => 'password', 'role' => User::ROLE_FRONTDESK]);
        User::create(['name' => '财务小李', 'email' => 'finance@hotel.local', 'password' => 'password', 'role' => User::ROLE_FINANCE]);

        $days = app(BusinessDayService::class);
        $folios = app(FolioService::class);
        $gateway = app(MockPaymentGateway::class);
        $audit = app(NightAuditService::class);

        $yesterday = Carbon::yesterday();
        $today = Carbon::today();

        // ---- 昨日营业 ----
        $days->ensureExists($yesterday->toDateString());

        // 张三 23:50 跨午夜入住 301
        $stayA = $folios->checkIn('301', '张三', $yesterday->copy()->setTime(23, 50));
        // 房费次日 00:10 过账，归属入住当晚（昨日）
        $folios->postRoomCharge($stayA, $yesterday->toDateString(), 58800, $today->copy()->setTime(0, 10));
        // 现金押金（立即到账）
        $gateway->pay($stayA->folio, 30000, 'cash', false, $yesterday->copy()->setTime(23, 55));

        // 李四 18:00 入住 302
        $stayB = $folios->checkIn('302', '李四', $yesterday->copy()->setTime(18, 0));
        $folios->postRoomCharge($stayB, $yesterday->toDateString(), 68800, $today->copy()->setTime(0, 5));
        $folios->postAddon($stayB->folio, '迷你吧消费', 5600, $yesterday->copy()->setTime(21, 30));
        // 刷卡 700 元，回执晚到（pending）——封账后由演示脚本触发迟到收款调整
        $gateway->pay($stayB->folio, 70000, 'card', true, $yesterday->copy()->setTime(22, 40));

        // ---- 昨日夜审：核对并封账 ----
        $yesterdayDay = $days->findByDate($yesterday->toDateString());
        $audit->verifyFolio($yesterdayDay, $stayA->folio, $auditorA);
        $audit->verifyFolio($yesterdayDay, $stayB->folio, $auditorA);
        $audit->close($yesterdayDay, $auditorA); // 自动开启今日

        // ---- 今日营业（保持未核对状态，供夜审页面操作） ----
        $stayC = $folios->checkIn('303', '王五', $today->copy()->setTime(10, 15));
        $folios->postRoomCharge($stayC, $today->toDateString(), 58800, $today->copy()->setTime(10, 20));
        $folios->postAddon($stayC->folio, '洗衣服务', 3800, $today->copy()->setTime(11, 0));
        $gateway->pay($stayC->folio, 20000, 'cash', false, $today->copy()->setTime(12, 0));
    }
}
