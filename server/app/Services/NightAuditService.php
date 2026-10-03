<?php

namespace App\Services;

use App\Exceptions\AlreadyClosedException;
use App\Exceptions\UncheckedFoliosException;
use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\FolioCheck;
use App\Models\FolioItem;
use App\Models\NightAuditSnapshot;
use App\Models\Payment;
use App\Models\SnapshotFolio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 夜审：汇总当日账务、逐房单核对、原子封账并保存不可变快照。
 */
class NightAuditService
{
    public function __construct(private BusinessDayResolver $resolver) {}

    /**
     * 某营业日的账务汇总（房费/加购/现金/银行卡/退款/调整）+ 房单明细。
     */
    public function summary(BusinessDay $day): array
    {
        $date = $day->date->toDateString();

        $items = FolioItem::where('business_date', $date)->get();
        $payments = Payment::where('business_date', $date)->get();
        $adjustments = Adjustment::where('business_date', $date)->get();

        $totals = $this->totals($items, $payments, $adjustments);

        // 当日有账务活动的房单（含仅有调整的房单）
        $folioIds = collect()
            ->merge($items->pluck('folio_id'))
            ->merge($payments->pluck('folio_id'))
            ->merge($adjustments->pluck('folio_id'))
            ->unique()->values();

        $checks = FolioCheck::where('business_day_id', $day->id)->get()->keyBy('folio_id');

        $folios = Folio::whereIn('id', $folioIds)->get()
            ->map(fn (Folio $folio) => $this->folioActivity($folio, $date, $checks->get($folio->id)))
            ->sortBy('room_no')->values();

        return [
            'day' => $day,
            'totals' => $totals,
            'folios' => $folios,
            'unchecked_count' => $folios->where('checked', false)->count(),
        ];
    }

    private function totals(Collection $items, Collection $payments, Collection $adjustments): array
    {
        $room = (int) $items->where('kind', FolioItem::KIND_ROOM)->sum('amount_cents');
        $addon = (int) $items->where('kind', FolioItem::KIND_ADDON)->sum('amount_cents');
        $cash = (int) $payments->where('method', Payment::METHOD_CASH)->where('amount_cents', '>', 0)->sum('amount_cents');
        $card = (int) $payments->where('method', Payment::METHOD_CARD)->where('amount_cents', '>', 0)->sum('amount_cents');
        $refund = (int) abs($payments->where('amount_cents', '<', 0)->sum('amount_cents'));
        $adjRevenue = (int) $adjustments->where('affects_revenue', true)->sum('amount_cents');
        $adjMemo = (int) $adjustments->where('affects_revenue', false)->sum('amount_cents');

        return [
            'room_cents' => $room,
            'addon_cents' => $addon,
            'cash_cents' => $cash,
            'card_cents' => $card,
            'refund_cents' => $refund,
            'adjustment_revenue_cents' => $adjRevenue,
            'adjustment_memo_cents' => $adjMemo,
            // 应收口径：房费 + 加购 + 营收性调整；实收口径：现金 + 银行卡 - 退款
            'charge_total_cents' => $room + $addon + $adjRevenue,
            'received_total_cents' => $cash + $card - $refund,
        ];
    }

    private function folioActivity(Folio $folio, string $date, ?FolioCheck $check): array
    {
        $items = $folio->items()->where('business_date', $date)->get();
        $payments = $folio->payments()->where('business_date', $date)->get();
        $adjustments = $folio->adjustments()->where('business_date', $date)->get();

        return [
            'folio' => $folio,
            'items' => $items,
            'payments' => $payments,
            'adjustments' => $adjustments,
            'room_cents' => (int) $items->where('kind', FolioItem::KIND_ROOM)->sum('amount_cents'),
            'addon_cents' => (int) $items->where('kind', FolioItem::KIND_ADDON)->sum('amount_cents'),
            'paid_cents' => (int) $payments->sum('amount_cents'),
            'checked' => $check !== null,
            'checked_by' => $check?->auditor,
            'checked_at' => $check?->checked_at,
        ];
    }

    /**
     * 核对 / 取消核对某房单（仅开放日可操作）。
     */
    public function checkFolio(BusinessDay $day, Folio $folio, string $auditor): FolioCheck
    {
        abort_unless($day->isOpen(), 409, "营业日 {$day->date->toDateString()} 已封账，不能修改核对状态");

        return FolioCheck::updateOrCreate(
            ['folio_id' => $folio->id, 'business_day_id' => $day->id],
            ['auditor' => $auditor, 'checked_at' => now()],
        );
    }

    public function uncheckFolio(BusinessDay $day, Folio $folio): void
    {
        abort_unless($day->isOpen(), 409, "营业日 {$day->date->toDateString()} 已封账，不能修改核对状态");

        FolioCheck::where('folio_id', $folio->id)->where('business_day_id', $day->id)->delete();
    }

    /**
     * 封账。并发安全：先把营业日原子置为 closed（仅当 open），
     * 影响行数为 0 说明另一夜审员已抢先封账 → 409。
     * 后续校验失败则整个事务回滚，状态恢复 open。
     */
    public function close(BusinessDay $day, string $auditor): NightAuditSnapshot
    {
        return DB::transaction(function () use ($day, $auditor) {
            $date = $day->date->toDateString();

            $affected = BusinessDay::where('id', $day->id)
                ->where('status', BusinessDay::STATUS_OPEN)
                ->update([
                    'status' => BusinessDay::STATUS_CLOSED,
                    'closed_at' => now(),
                    'closed_by' => $auditor,
                ]);

            if ($affected === 0) {
                throw new AlreadyClosedException($date);
            }

            $summary = $this->summary($day->refresh());

            if ($summary['folios']->isEmpty()) {
                // 允许空日封账（无账务活动），快照为零值
            }

            $unchecked = $summary['folios']->where('checked', false);
            if ($unchecked->isNotEmpty()) {
                throw new UncheckedFoliosException(
                    $unchecked->map(fn ($f) => $f['folio']->folio_no)->values()->all()
                );
            }

            $snapshot = NightAuditSnapshot::create([
                'business_day_id' => $day->id,
                'room_total_cents' => $summary['totals']['room_cents'],
                'addon_total_cents' => $summary['totals']['addon_cents'],
                'cash_total_cents' => $summary['totals']['cash_cents'],
                'card_total_cents' => $summary['totals']['card_cents'],
                'refund_total_cents' => $summary['totals']['refund_cents'],
                'adjustment_total_cents' => $summary['totals']['adjustment_revenue_cents'],
                'net_total_cents' => $summary['totals']['charge_total_cents'],
                'folio_count' => $summary['folios']->count(),
                'payload' => [
                    'date' => $date,
                    'closed_by' => $auditor,
                    'totals' => $summary['totals'],
                    'adjustments' => Adjustment::where('business_date', $date)->get()->toArray(),
                ],
            ]);

            foreach ($summary['folios'] as $activity) {
                $folio = $activity['folio'];
                SnapshotFolio::create([
                    'night_audit_snapshot_id' => $snapshot->id,
                    'folio_id' => $folio->id,
                    'folio_no' => $folio->folio_no,
                    'guest_name' => $folio->guest_name,
                    'room_no' => $folio->room_no,
                    'room_total_cents' => $activity['room_cents'],
                    'addon_total_cents' => $activity['addon_cents'],
                    'payment_total_cents' => (int) $activity['payments']->where('amount_cents', '>', 0)->sum('amount_cents'),
                    'refund_total_cents' => (int) abs($activity['payments']->where('amount_cents', '<', 0)->sum('amount_cents')),
                    'balance_cents' => $activity['room_cents'] + $activity['addon_cents'] - $activity['paid_cents'],
                    'payload' => [
                        'items' => $activity['items']->toArray(),
                        'payments' => $activity['payments']->toArray(),
                        'adjustments' => $activity['adjustments']->toArray(),
                        'checked_by' => $activity['checked_by'],
                    ],
                ]);
            }

            return $snapshot;
        });
    }

    /**
     * 封账后差额核对：某已封账营业日的快照 vs 后续关联调整。
     * 财务可沿调整单追到原交易，汇总与调整流水逐笔对账。
     */
    public function reconciliation(BusinessDay $day): array
    {
        $date = $day->date->toDateString();
        $snapshot = $day->snapshot;

        $laterAdjustments = Adjustment::where('original_business_date', $date)
            ->orderBy('created_at')
            ->with(['folio', 'originalItem', 'originalPayment'])
            ->get();

        $revenueDelta = (int) $laterAdjustments->where('affects_revenue', true)->sum('amount_cents');
        $memoDelta = (int) $laterAdjustments->where('affects_revenue', false)->sum('amount_cents');

        return [
            'day' => $day,
            'snapshot' => $snapshot,
            'later_adjustments' => $laterAdjustments,
            'revenue_delta_cents' => $revenueDelta,
            'memo_delta_cents' => $memoDelta,
            // 调整后应收 = 快照应收 + 后续营收性调整
            'revised_charge_total_cents' => ($snapshot?->net_total_cents ?? 0) + $revenueDelta,
        ];
    }
}
