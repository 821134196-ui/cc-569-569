<?php

namespace Tests\Feature;

use App\Domain\Exceptions\DayAlreadyClosedException;
use App\Domain\Exceptions\UnverifiedFoliosException;
use App\Domain\FolioService;
use App\Domain\NightAuditService;
use App\Models\BusinessDay;
use App\Models\NightAuditSnapshot;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 封账规则：未核对不得封账；并发封账只成功一次；封账生成不可修改快照。
 */
class NightAuditCloseTest extends TestCase
{
    private FolioService $folios;
    private NightAuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folios = app(FolioService::class);
        $this->audit = app(NightAuditService::class);
    }

    public function test_cannot_close_with_unverified_folios(): void
    {
        $day = $this->openDay('2026-10-03');
        $stay = $this->folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));
        $this->folios->postRoomCharge($stay, '2026-10-03', 58800);

        // 未核对直接封账 → 422，且营业日仍是开放状态
        $response = $this->asUser($this->auditor)->postJson('/api/audit/close', ['business_day_id' => $day->id]);
        $response->assertStatus(422)
            ->assertJsonPath('unverified_folios.0', $stay->folio->folio_no);

        $this->assertTrue(BusinessDay::where('date', '2026-10-03')->sole()->isOpen());
        $this->assertSame(0, NightAuditSnapshot::count());
    }

    public function test_close_after_verifying_all_folios_creates_snapshot_and_opens_next_day(): void
    {
        $day = $this->openDay('2026-10-03');
        $stayA = $this->folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));
        $stayB = $this->folios->checkIn('302', '李四', Carbon::parse('2026-10-03 16:00:00'));
        $this->folios->postRoomCharge($stayA, '2026-10-03', 58800);
        $this->folios->postRoomCharge($stayB, '2026-10-03', 68800);

        $this->asUser($this->auditor)->postJson("/api/audit/folios/{$stayA->folio->id}/verify")->assertOk();
        $this->asUser($this->auditor)->postJson("/api/audit/folios/{$stayB->folio->id}/verify")->assertOk();

        $response = $this->asUser($this->auditor)->postJson('/api/audit/close', ['business_day_id' => $day->id]);
        $response->assertOk()->assertJsonPath('snapshot.business_date', '2026-10-03');

        $snapshot = NightAuditSnapshot::sole();
        $this->assertSame(127600, $snapshot->room_charge_cents);
        $this->assertSame(2, $snapshot->folios()->count());
        $this->assertSame(2, $snapshot->items()->count());

        // 次日自动开启
        $this->assertTrue(BusinessDay::where('date', '2026-10-04')->sole()->isOpen());
    }

    public function test_two_auditors_closing_simultaneously_only_one_wins(): void
    {
        $this->openDay('2026-10-03');
        $stay = $this->folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));
        $this->folios->postRoomCharge($stay, '2026-10-03', 58800);

        $day = BusinessDay::where('date', '2026-10-03')->first();
        $this->audit->verifyFolio($day, $stay->folio, $this->auditor);

        // 夜审员甲先封账成功
        $this->audit->close($day, $this->auditor);

        // 夜审员乙同时（基于旧的 open 状态）提交 → 原子占位失败
        try {
            $this->audit->close($day, $this->auditorB);
            $this->fail('第二个封账请求应当失败');
        } catch (DayAlreadyClosedException $e) {
            $this->assertStringContainsString('已封账', $e->getMessage());
        }

        // 全局只有一份快照
        $this->assertSame(1, NightAuditSnapshot::count());
        $this->assertSame($this->auditor->id, NightAuditSnapshot::sole()->closed_by);
    }

    public function test_second_close_request_via_api_returns_409(): void
    {
        $day = $this->openDay('2026-10-03');
        $stay = $this->folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));
        $this->folios->postRoomCharge($stay, '2026-10-03', 58800);
        $this->asUser($this->auditor)->postJson("/api/audit/folios/{$stay->folio->id}/verify");

        // 夜审员甲封账成功
        $this->asUser($this->auditor)
            ->postJson('/api/audit/close', ['business_day_id' => $day->id])
            ->assertOk();

        // 夜审员乙对同一营业日的封账请求 → 409，且不会生成第二份快照
        $this->asUser($this->auditorB)
            ->postJson('/api/audit/close', ['business_day_id' => $day->id])
            ->assertStatus(409);

        $this->assertSame(1, NightAuditSnapshot::where('business_date', '2026-10-03')->count());
    }

    public function test_non_auditor_cannot_verify_or_close(): void
    {
        $this->openDay('2026-10-03');
        $stay = $this->folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));
        $this->folios->postRoomCharge($stay, '2026-10-03', 58800);

        $this->asUser($this->frontdesk)->postJson("/api/audit/folios/{$stay->folio->id}/verify")->assertStatus(403);
        $this->asUser($this->finance)->postJson('/api/audit/close')->assertStatus(403);
    }
}
