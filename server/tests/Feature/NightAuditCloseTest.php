<?php

namespace Tests\Feature;

use App\Exceptions\AlreadyClosedException;
use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\NightAuditSnapshot;
use App\Services\BusinessDayResolver;
use App\Services\FolioService;
use App\Services\NightAuditService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 夜审封账：核对前置、并发只封一次、快照不可变。
 */
class NightAuditCloseTest extends TestCase
{
    use RefreshDatabase;

    private NightAuditService $audit;
    private BusinessDayResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-02 09:00:00');
        $this->audit = app(NightAuditService::class);
        $this->resolver = app(BusinessDayResolver::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** 造一个有一笔房费+一笔收款的营业日 2026-10-01 */
    private function makeDayWithActivity(): array
    {
        $folios = app(FolioService::class);
        $folio = $folios->checkIn('张三', '301', CarbonImmutable::parse('2026-10-01 15:00'), 1, 38800);
        app(PaymentService::class)->record($folio, 'cash', 38800, CarbonImmutable::parse('2026-10-01 15:05'));
        $day = BusinessDay::where('date', '2026-10-01')->sole();

        return [$day, $folio];
    }

    public function test_cannot_close_while_folios_unchecked(): void
    {
        [$day] = $this->makeDayWithActivity();

        $response = $this->postJson("/api/audit/2026-10-01/close", ['auditor' => '张夜审']);

        $response->assertStatus(422);
        $this->assertSame('open', $day->refresh()->status);
        $this->assertSame(0, NightAuditSnapshot::count());
    }

    public function test_close_succeeds_after_all_folios_checked(): void
    {
        [$day, $folio] = $this->makeDayWithActivity();

        $this->postJson("/api/audit/2026-10-01/folios/{$folio->id}/check", ['auditor' => '张夜审'])->assertOk();
        $response = $this->postJson('/api/audit/2026-10-01/close', ['auditor' => '张夜审']);

        $response->assertOk()->assertJson(['closed' => true]);
        $this->assertSame('closed', $day->refresh()->status);
        $this->assertSame('张夜审', $day->closed_by);

        $snapshot = NightAuditSnapshot::sole();
        $this->assertSame(38800, $snapshot->room_total_cents);
        $this->assertSame(38800, $snapshot->cash_total_cents);
        $this->assertSame(1, $snapshot->folio_count);
        $this->assertSame(1, $snapshot->folios()->count());
    }

    public function test_second_close_is_rejected_with_409(): void
    {
        [$day, $folio] = $this->makeDayWithActivity();
        $this->audit->checkFolio($day, $folio, '张夜审');
        $this->audit->close($day, '张夜审');

        $response = $this->postJson('/api/audit/2026-10-01/close', ['auditor' => '李夜审']);
        $response->assertStatus(409);
        $this->assertSame(1, NightAuditSnapshot::count());
    }

    public function test_concurrent_close_only_one_wins(): void
    {
        [$day, $folio] = $this->makeDayWithActivity();
        $this->audit->checkFolio($day, $folio, '张夜审');

        // 模拟两个夜审员同时拿到 open 状态后先后提交
        $dayForAuditorA = BusinessDay::find($day->id);
        $dayForAuditorB = BusinessDay::find($day->id);

        $snapshotA = $this->audit->close($dayForAuditorA, '张夜审');
        $this->assertNotNull($snapshotA);

        try {
            $this->audit->close($dayForAuditorB, '李夜审');
            $this->fail('第二个封账请求应被拒绝');
        } catch (AlreadyClosedException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, NightAuditSnapshot::count());
        $this->assertSame('张夜审', $day->refresh()->closed_by);
    }

    public function test_snapshot_is_immutable(): void
    {
        [$day, $folio] = $this->makeDayWithActivity();
        $this->audit->checkFolio($day, $folio, '张夜审');
        $snapshot = $this->audit->close($day, '张夜审');

        $this->expectException(RuntimeException::class);
        $snapshot->update(['cash_total_cents' => 0]);
    }

    public function test_snapshot_cannot_be_deleted(): void
    {
        [$day, $folio] = $this->makeDayWithActivity();
        $this->audit->checkFolio($day, $folio, '张夜审');
        $snapshot = $this->audit->close($day, '张夜审');

        $this->expectException(RuntimeException::class);
        $snapshot->delete();
    }

    public function test_adjustment_is_append_only(): void
    {
        [$day, $folio] = $this->makeDayWithActivity();
        $this->audit->checkFolio($day, $folio, '张夜审');
        $this->audit->close($day, '张夜审');

        // 触发一条自动调整单（迟到收款）
        $payment = app(PaymentService::class)->record($folio, 'cash', 10000, CarbonImmutable::parse('2026-10-01 20:00'));
        $adjustment = Adjustment::where('original_payment_id', $payment->id)->sole();

        $this->expectException(RuntimeException::class);
        $adjustment->update(['amount_cents' => 1]);
    }

    public function test_cannot_check_folio_on_closed_day(): void
    {
        [$day, $folio] = $this->makeDayWithActivity();
        $this->audit->checkFolio($day, $folio, '张夜审');
        $this->audit->close($day, '张夜审');

        $response = $this->postJson("/api/audit/2026-10-01/folios/{$folio->id}/check", ['auditor' => '李夜审']);
        $response->assertStatus(409);
    }
}
