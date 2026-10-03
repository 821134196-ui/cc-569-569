<?php

namespace App\Domain;

use App\Domain\Exceptions\DayAlreadyClosedException;
use App\Domain\Exceptions\UnverifiedFoliosException;
use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\FolioItem;
use App\Models\FolioVerification;
use App\Models\NightAuditSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NightAuditService
{
    public function __construct(private BusinessDayService $days) {}

    /**
     * 夜审页面数据：汇总 + 逐房单明细 + 核对状态。
     */
    public function summary(BusinessDay $day): array
    {
        $date = $day->date->toDateString();

        $items = FolioItem::with('folio')
            ->where('business_date', $date)
            ->orderBy('posted_at')
            ->get();

        $settled = $items->where('status', FolioItem::STATUS_SETTLED);

        $buckets = [
            'room_charge_cents' => $this->sum($settled, FolioItem::TYPE_ROOM_CHARGE),
            'addon_cents' => $this->sum($settled, FolioItem::TYPE_ADDON),
            'cash_cents' => $this->sum($settled, FolioItem::TYPE_PAYMENT, FolioItem::METHOD_CASH),
            'card_cents' => $this->sum($settled, FolioItem::TYPE_PAYMENT, FolioItem::METHOD_CARD),
            'refund_cents' => $this->sum($settled, FolioItem::TYPE_REFUND),
            'adjustment_cents' => $this->sum($settled, FolioItem::TYPE_ADJUSTMENT),
        ];
        $buckets['net_cents'] = array_sum($buckets);

        $folios = $items->groupBy('folio_id')->map(function (Collection $folioItems) use ($day) {
            $folio = $folioItems->first()->folio;
            $verification = FolioVerification::with('verifier')
                ->where('folio_id', $folio->id)
                ->where('business_day_id', $day->id)
                ->first();

            return [
                'folio_id' => $folio->id,
                'folio_no' => $folio->folio_no,
                'guest_name' => $folio->guest_name,
                'room_no' => $folio->room_no,
                'verified' => $verification !== null,
                'verified_by' => $verification?->verifier?->name,
                'verified_at' => $verification?->verified_at?->toIso8601String(),
                'items' => $folioItems->map(fn (FolioItem $i) => $this->itemArray($i))->values(),
            ];
        })->values();

        return [
            'day' => [
                'id' => $day->id,
                'date' => $date,
                'status' => $day->status,
                'closed_by' => $day->closedBy?->name,
                'closed_at' => $day->closed_at?->toIso8601String(),
            ],
            'buckets' => $buckets,
            'folios' => $folios,
            'unverified_count' => $folios->where('verified', false)->count(),
            'can_close' => $day->isOpen() && $folios->where('verified', false)->isEmpty(),
            'snapshot' => $day->snapshot ? $this->snapshotArray($day->snapshot) : null,
        ];
    }

    /** 夜审员核对某本房单（幂等）。 */
    public function verifyFolio(BusinessDay $day, Folio $folio, User $auditor): FolioVerification
    {
        return FolioVerification::firstOrCreate(
            ['folio_id' => $folio->id, 'business_day_id' => $day->id],
            ['verified_by' => $auditor->id, 'verified_at' => now()],
        );
    }

    /**
     * 封账。并发安全：条件 UPDATE 保证两个夜审员同时操作时只有一个成功，
     * 另一个收到 DayAlreadyClosedException（HTTP 409）。
     * 整个封账在单个事务内完成：校验未通过则全部回滚。
     */
    public function close(BusinessDay $day, User $auditor): NightAuditSnapshot
    {
        return DB::transaction(function () use ($day, $auditor) {
            $date = $day->date->toDateString();

            // 1) 原子占位：只有仍处 open 状态才能封账。并发下只有一方影响行数为 1。
            $affected = BusinessDay::whereKey($day->id)
                ->where('status', BusinessDay::STATUS_OPEN)
                ->update([
                    'status' => BusinessDay::STATUS_CLOSED,
                    'closed_by' => $auditor->id,
                    'closed_at' => now(),
                ]);
            if ($affected === 0) {
                throw new DayAlreadyClosedException($date);
            }

            // 2) 仍有未核对房单则整体回滚（上一步的占位也随之撤销）。
            $unverified = $this->unverifiedFolioNos($day);
            if ($unverified !== []) {
                throw new UnverifiedFoliosException($unverified);
            }

            // 3) 生成不可修改的三层快照（头 / 房单 / 条目）。
            $snapshot = $this->buildSnapshot($day, $auditor);

            // 4) 自动开启下一营业日。
            $this->days->ensureExists($day->date->copy()->addDay()->toDateString());

            return $snapshot;
        });
    }

    /** @return array<int, string> 未核对房单号 */
    private function unverifiedFolioNos(BusinessDay $day): array
    {
        return Folio::whereHas('items', fn ($q) => $q->where('business_date', $day->date->toDateString()))
            ->whereDoesntHave('verifications', fn ($q) => $q->where('business_day_id', $day->id))
            ->pluck('folio_no')
            ->all();
    }

    private function buildSnapshot(BusinessDay $day, User $auditor): NightAuditSnapshot
    {
        $date = $day->date->toDateString();
        $items = FolioItem::with('folio')->where('business_date', $date)->orderBy('posted_at')->get();
        $settled = $items->where('status', FolioItem::STATUS_SETTLED);

        $snapshot = NightAuditSnapshot::create([
            'business_day_id' => $day->id,
            'business_date' => $date,
            'room_charge_cents' => $this->sum($settled, FolioItem::TYPE_ROOM_CHARGE),
            'addon_cents' => $this->sum($settled, FolioItem::TYPE_ADDON),
            'cash_cents' => $this->sum($settled, FolioItem::TYPE_PAYMENT, FolioItem::METHOD_CASH),
            'card_cents' => $this->sum($settled, FolioItem::TYPE_PAYMENT, FolioItem::METHOD_CARD),
            'refund_cents' => $this->sum($settled, FolioItem::TYPE_REFUND),
            'adjustment_cents' => $this->sum($settled, FolioItem::TYPE_ADJUSTMENT),
            'net_cents' => (int) $settled->sum('amount_cents'),
            'folio_count' => $items->pluck('folio_id')->unique()->count(),
            'item_count' => $items->count(),
            'closed_by' => $auditor->id,
            'closed_at' => now(),
        ]);

        $items->groupBy('folio_id')->each(function (Collection $folioItems) use ($snapshot, $day) {
            $folio = $folioItems->first()->folio;
            $settledItems = $folioItems->where('status', FolioItem::STATUS_SETTLED);
            $verification = FolioVerification::where('folio_id', $folio->id)
                ->where('business_day_id', $day->id)->first();

            $snapshot->folios()->create([
                'folio_id' => $folio->id,
                'folio_no' => $folio->folio_no,
                'guest_name' => $folio->guest_name,
                'room_no' => $folio->room_no,
                'charge_cents' => (int) $settledItems->whereIn('type', [FolioItem::TYPE_ROOM_CHARGE, FolioItem::TYPE_ADDON])->sum('amount_cents'),
                'payment_cents' => (int) $settledItems->where('type', FolioItem::TYPE_PAYMENT)->sum('amount_cents'),
                'refund_cents' => (int) $settledItems->where('type', FolioItem::TYPE_REFUND)->sum('amount_cents'),
                'balance_cents' => $folio->balanceCents(),
                'verified_by' => $verification?->verified_by,
                'verified_at' => $verification?->verified_at,
            ]);

            foreach ($folioItems as $item) {
                $snapshot->items()->create([
                    'folio_id' => $item->folio_id,
                    'folio_item_id' => $item->id,
                    'type' => $item->type,
                    'description' => $item->description,
                    'amount_cents' => $item->amount_cents,
                    'business_date' => $item->business_date->toDateString(),
                    'posted_at' => $item->posted_at,
                    'status' => $item->status,
                ]);
            }
        });

        return $snapshot->fresh(['folios', 'items']);
    }

    public function snapshotArray(NightAuditSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'business_date' => $snapshot->business_date->toDateString(),
            'room_charge_cents' => $snapshot->room_charge_cents,
            'addon_cents' => $snapshot->addon_cents,
            'cash_cents' => $snapshot->cash_cents,
            'card_cents' => $snapshot->card_cents,
            'refund_cents' => $snapshot->refund_cents,
            'adjustment_cents' => $snapshot->adjustment_cents,
            'net_cents' => $snapshot->net_cents,
            'folio_count' => $snapshot->folio_count,
            'item_count' => $snapshot->item_count,
            'closed_by' => $snapshot->closer?->name,
            'closed_at' => $snapshot->closed_at?->toIso8601String(),
        ];
    }

    private function sum(Collection $settled, string $type, ?string $method = null): int
    {
        return (int) $settled
            ->where('type', $type)
            ->when($method, fn ($c) => $c->where('method', $method))
            ->sum('amount_cents');
    }

    private function itemArray(FolioItem $i): array
    {
        return [
            'id' => $i->id,
            'type' => $i->type,
            'description' => $i->description,
            'amount_cents' => $i->amount_cents,
            'business_date' => $i->business_date->toDateString(),
            'posted_at' => $i->posted_at->toIso8601String(),
            'status' => $i->status,
            'method' => $i->method,
            'adjustment_id' => $i->adjustment_id,
        ];
    }
}
