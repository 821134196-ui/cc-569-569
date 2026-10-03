<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Services\NightAuditService;
use Illuminate\Http\JsonResponse;

class BusinessDayController extends Controller
{
    public function __construct(private NightAuditService $audit) {}

    /**
     * 营业日列表（含快照汇总）。
     */
    public function index(): JsonResponse
    {
        $days = BusinessDay::with('snapshot')->orderByDesc('date')->get()->map(fn (BusinessDay $d) => [
            'date' => $d->date->toDateString(),
            'status' => $d->status,
            'closed_at' => $d->closed_at?->toIso8601String(),
            'closed_by' => $d->closed_by,
            'snapshot' => $d->snapshot ? [
                'room_total_cents' => $d->snapshot->room_total_cents,
                'addon_total_cents' => $d->snapshot->addon_total_cents,
                'cash_total_cents' => $d->snapshot->cash_total_cents,
                'card_total_cents' => $d->snapshot->card_total_cents,
                'refund_total_cents' => $d->snapshot->refund_total_cents,
                'adjustment_total_cents' => $d->snapshot->adjustment_total_cents,
                'net_total_cents' => $d->snapshot->net_total_cents,
                'folio_count' => $d->snapshot->folio_count,
            ] : null,
        ]);

        return response()->json(['days' => $days]);
    }

    /**
     * 营业日详情：快照（不可变）+ 落账于当日的调整 + 关联当日的后续调整 + 差额核对。
     */
    public function show(string $date): JsonResponse
    {
        $day = BusinessDay::where('date', $date)->firstOrFail();
        $recon = $this->audit->reconciliation($day);

        $snapshot = $day->snapshot?->load('folios');

        // 落账于当日的调整流水（无论原归属日）
        $landedAdjustments = Adjustment::where('business_date', $date)
            ->with('folio')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'day' => [
                'date' => $day->date->toDateString(),
                'status' => $day->status,
                'closed_at' => $day->closed_at?->toIso8601String(),
                'closed_by' => $day->closed_by,
            ],
            'snapshot' => $snapshot ? [
                'room_total_cents' => $snapshot->room_total_cents,
                'addon_total_cents' => $snapshot->addon_total_cents,
                'cash_total_cents' => $snapshot->cash_total_cents,
                'card_total_cents' => $snapshot->card_total_cents,
                'refund_total_cents' => $snapshot->refund_total_cents,
                'adjustment_total_cents' => $snapshot->adjustment_total_cents,
                'net_total_cents' => $snapshot->net_total_cents,
                'folio_count' => $snapshot->folio_count,
                'created_at' => $snapshot->created_at?->toIso8601String(),
                'folios' => $snapshot->folios->map(fn ($f) => [
                    'folio_id' => $f->folio_id,
                    'folio_no' => $f->folio_no,
                    'guest_name' => $f->guest_name,
                    'room_no' => $f->room_no,
                    'room_total_cents' => $f->room_total_cents,
                    'addon_total_cents' => $f->addon_total_cents,
                    'payment_total_cents' => $f->payment_total_cents,
                    'refund_total_cents' => $f->refund_total_cents,
                    'balance_cents' => $f->balance_cents,
                    'payload' => $f->payload,
                ])->values(),
            ] : null,
            'landed_adjustments' => $landedAdjustments->map(fn ($a) => $this->adjustmentResource($a)),
            'later_adjustments' => $recon['later_adjustments']->map(fn ($a) => $this->adjustmentResource($a)),
            'reconciliation' => [
                'revenue_delta_cents' => $recon['revenue_delta_cents'],
                'memo_delta_cents' => $recon['memo_delta_cents'],
                'revised_charge_total_cents' => $recon['revised_charge_total_cents'],
            ],
        ]);
    }

    private function adjustmentResource(Adjustment $a): array
    {
        return [
            'id' => $a->id,
            'adjustment_no' => $a->adjustment_no,
            'folio_id' => $a->folio_id,
            'folio_no' => $a->folio?->folio_no,
            'guest_name' => $a->folio?->guest_name,
            'business_date' => $a->business_date->toDateString(),
            'original_business_date' => $a->original_business_date->toDateString(),
            'kind' => $a->kind,
            'amount_cents' => $a->amount_cents,
            'affects_revenue' => $a->affects_revenue,
            'reason' => $a->reason,
            'original_item_id' => $a->original_item_id,
            'original_payment_id' => $a->original_payment_id,
            'created_by' => $a->created_by,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
