<?php

namespace App\Http\Controllers;

use App\Domain\FinanceService;
use App\Models\BusinessDay;
use Illuminate\Http\JsonResponse;

/**
 * 财务视图：营业日列表、封账日核对报告、全局调整流水对账。
 */
class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    /** 营业日列表：状态 + 快照/有效净额。 */
    public function days(): JsonResponse
    {
        $days = BusinessDay::with('snapshot')->orderByDesc('date')->get()->map(fn (BusinessDay $d) => [
            'date' => $d->date->toDateString(),
            'status' => $d->status,
            'closed_by' => $d->closedBy?->name,
            'closed_at' => $d->closed_at?->toIso8601String(),
            'snapshot_net_cents' => $d->snapshot?->net_cents,
        ]);

        return response()->json($days);
    }

    /** 封账日核对报告：快照 + 关联调整 + 有效数字 + 对账结果。 */
    public function dayReport(string $date): JsonResponse
    {
        $day = BusinessDay::with('snapshot.closer')->where('date', $date)->firstOrFail();

        if ($day->isOpen()) {
            return response()->json(['message' => '该营业日尚未封账，无快照'], 422);
        }

        return response()->json($this->finance->closedDayReport($day));
    }

    /** 全局对账：调整单合计与房账调整条目合计必须一致。 */
    public function reconciliation(): JsonResponse
    {
        return response()->json($this->finance->ledgerReconciliation());
    }
}
