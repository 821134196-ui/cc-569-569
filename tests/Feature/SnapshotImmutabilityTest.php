<?php

namespace Tests\Feature;

use App\Domain\AdjustmentService;
use App\Domain\FolioService;
use App\Domain\NightAuditService;
use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\FolioItem;
use App\Models\NightAuditSnapshot;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 数据库触发器保证：快照不可修改；已封账营业日的房账不可增删改；
 * 调整单只增不改；已封账营业日不得重开。
 */
class SnapshotImmutabilityTest extends TestCase
{
    private function closedDayWithActivity(): array
    {
        $folios = app(FolioService::class);
        $audit = app(NightAuditService::class);

        $day = $this->openDay('2026-10-03');
        $stay = $folios->checkIn('301', '张三', Carbon::parse('2026-10-03 15:00:00'));
        $item = $folios->postRoomCharge($stay, '2026-10-03', 58800);
        $audit->verifyFolio($day, $stay->folio, $this->auditor);
        $snapshot = $audit->close($day, $this->auditor);

        return [$day->fresh(), $stay, $item, $snapshot];
    }

    public function test_snapshot_cannot_be_updated_or_deleted(): void
    {
        [, , , $snapshot] = $this->closedDayWithActivity();

        $this->expectException(QueryException::class);
        DB::table('night_audit_snapshots')->where('id', $snapshot->id)->update(['net_cents' => 0]);
    }

    public function test_snapshot_delete_is_blocked(): void
    {
        [, , , $snapshot] = $this->closedDayWithActivity();

        $this->expectException(QueryException::class);
        DB::table('night_audit_snapshots')->where('id', $snapshot->id)->delete();
    }

    public function test_snapshot_items_are_immutable_too(): void
    {
        [, , , $snapshot] = $this->closedDayWithActivity();

        $this->expectException(QueryException::class);
        DB::table('snapshot_items')->where('snapshot_id', $snapshot->id)->update(['amount_cents' => 1]);
    }

    public function test_cannot_insert_item_into_closed_day(): void
    {
        [, $stay] = $this->closedDayWithActivity();

        // 直接向已封账的 10-03 插入条目 → 被触发器拒绝
        $this->expectException(QueryException::class);
        $stay->folio->items()->create([
            'type' => FolioItem::TYPE_ADDON,
            'description' => '事后补录',
            'amount_cents' => 1000,
            'business_date' => '2026-10-03',
            'posted_at' => now(),
            'status' => FolioItem::STATUS_SETTLED,
        ]);
    }

    public function test_cannot_modify_item_of_closed_day(): void
    {
        [, , $item] = $this->closedDayWithActivity();

        $this->expectException(QueryException::class);
        $item->fresh()->update(['amount_cents' => 1]);
    }

    public function test_cannot_delete_item_of_closed_day(): void
    {
        [, , $item] = $this->closedDayWithActivity();

        $this->expectException(QueryException::class);
        $item->fresh()->delete();
    }

    public function test_closed_day_cannot_be_reopened(): void
    {
        [$day] = $this->closedDayWithActivity();

        $this->expectException(QueryException::class);
        $day->update(['status' => BusinessDay::STATUS_OPEN]);
    }

    public function test_adjustments_are_append_only(): void
    {
        [, $stay, $item] = $this->closedDayWithActivity();

        // 封账后通过调整单更正房费（落在新的开放日 10-04）——这是允许的
        $adjustment = app(AdjustmentService::class)->create(
            folio: $stay->folio,
            type: Adjustment::TYPE_ROOM_CHARGE_CORRECTION,
            amountCents: -5000,
            reasonCode: 'rate_error',
            reason: '房价录入错误',
            creator: $this->finance,
            originalItem: $item,
        );
        $this->assertSame('2026-10-04', $adjustment->business_date->toDateString());

        // 但调整单本身不可再改
        $this->expectException(QueryException::class);
        $adjustment->fresh()->update(['amount_cents' => -1]);
    }
}
