<?php

namespace App\Domain;

use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\FolioItem;
use Illuminate\Support\Collection;

/**
 * 财务核对：从调整金额追到原交易，汇总金额与调整流水对账。
 */
class FinanceService
{
    /**
     * 某个已封账营业日的核对报告：
     * 快照数字（冻结） + 后续关联到该日的调整单 = 当前有效数字。
     */
    public function closedDayReport(BusinessDay $day): array
    {
        $snapshot = $day->snapshot;
        $date = $day->date->toDateString();

        // 关联到该营业日的调整：原条目归属该日的所有调整单。
        $adjustments = Adjustment::with(['originalItem', 'ledgerItem', 'creator'])
            ->whereHas('originalItem', fn ($q) => $q->where('business_date', $date))
            ->orderBy('created_at')
            ->get();

        $byType = $adjustments->groupBy('type')->map->sum('amount_cents');

        $effective = [
            'room_charge_cents' => $snapshot->room_charge_cents + ($byType[Adjustment::TYPE_ROOM_CHARGE_CORRECTION] ?? 0),
            'addon_cents' => $snapshot->addon_cents + ($byType[Adjustment::TYPE_ADDON_CORRECTION] ?? 0),
            'payment_cents' => $snapshot->cash_cents + $snapshot->card_cents + ($byType[Adjustment::TYPE_LATE_PAYMENT] ?? 0),
            'refund_cents' => $snapshot->refund_cents + ($byType[Adjustment::TYPE_REFUND_CORRECTION] ?? 0),
        ];
        $adjustmentTotal = (int) $adjustments->sum('amount_cents');

        return [
            'date' => $date,
            'snapshot' => [
                'room_charge_cents' => $snapshot->room_charge_cents,
                'addon_cents' => $snapshot->addon_cents,
                'cash_cents' => $snapshot->cash_cents,
                'card_cents' => $snapshot->card_cents,
                'refund_cents' => $snapshot->refund_cents,
                'adjustment_cents' => $snapshot->adjustment_cents,
                'net_cents' => $snapshot->net_cents,
                'closed_by' => $snapshot->closer?->name,
                'closed_at' => $snapshot->closed_at?->toIso8601String(),
            ],
            'adjustments' => $adjustments->map(fn (Adjustment $a) => $this->adjustmentArray($a))->values(),
            'adjustment_total_cents' => $adjustmentTotal,
            'effective' => $effective,
            'effective_net_cents' => $snapshot->net_cents + $adjustmentTotal,
            // 对账：快照净额 + 调整流水合计 = 有效净额；每笔调整与其房账条目金额一致。
            'reconciled' => $this->adjustmentsTieToLedger($adjustments),
        ];
    }

    /**
     * 全局流水核对：每笔调整单金额 == 其生成的房账条目金额；
     * 调整单合计 == 房账 adjustment 条目合计。
     */
    public function ledgerReconciliation(): array
    {
        $adjustments = Adjustment::with('ledgerItem')->get();
        $perItemOk = $this->adjustmentsTieToLedger($adjustments);

        $adjustmentSum = (int) $adjustments->sum('amount_cents');
        $ledgerSum = (int) FolioItem::where('type', FolioItem::TYPE_ADJUSTMENT)->sum('amount_cents');

        return [
            'adjustment_count' => $adjustments->count(),
            'per_item_tied' => $perItemOk,
            'adjustment_total_cents' => $adjustmentSum,
            'ledger_adjustment_total_cents' => $ledgerSum,
            'totals_tied' => $adjustmentSum === $ledgerSum,
        ];
    }

    private function adjustmentsTieToLedger(Collection $adjustments): bool
    {
        return $adjustments->every(
            fn (Adjustment $a) => $a->ledgerItem !== null && $a->ledgerItem->amount_cents === $a->amount_cents
        );
    }

    private function adjustmentArray(Adjustment $a): array
    {
        return [
            'adjustment_no' => $a->adjustment_no,
            'business_date' => $a->business_date->toDateString(),
            'type' => $a->type,
            'amount_cents' => $a->amount_cents,
            'reason_code' => $a->reason_code,
            'reason' => $a->reason,
            'created_by' => $a->creator?->name,
            'created_at' => $a->created_at?->toIso8601String(),
            'folio_id' => $a->folio_id,
            'original_item' => $a->originalItem ? [
                'id' => $a->originalItem->id,
                'type' => $a->originalItem->type,
                'description' => $a->originalItem->description,
                'amount_cents' => $a->originalItem->amount_cents,
                'business_date' => $a->originalItem->business_date->toDateString(),
                'posted_at' => $a->originalItem->posted_at->toIso8601String(),
            ] : null,
        ];
    }
}
