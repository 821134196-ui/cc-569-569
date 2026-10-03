<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use Illuminate\Http\JsonResponse;

class FolioController extends Controller
{
    /**
     * 房单全量账务视图：账目项、收退款、调整单（财务追溯用）。
     */
    public function show(int $id): JsonResponse
    {
        $folio = Folio::with(['items', 'payments', 'adjustments'])->findOrFail($id);

        $charges = (int) $folio->items->sum('amount_cents')
            + (int) $folio->adjustments->where('affects_revenue', true)->sum('amount_cents');
        $paid = (int) $folio->payments->sum('amount_cents');

        return response()->json([
            'folio' => [
                'id' => $folio->id,
                'folio_no' => $folio->folio_no,
                'guest_name' => $folio->guest_name,
                'room_no' => $folio->room_no,
                'status' => $folio->status,
                'check_in_at' => $folio->check_in_at?->toIso8601String(),
                'planned_check_out_at' => $folio->planned_check_out_at?->toIso8601String(),
                'actual_check_out_at' => $folio->actual_check_out_at?->toIso8601String(),
                'nights' => $folio->nights,
                'room_rate_cents' => $folio->room_rate_cents,
                'room_charges_posted' => $folio->room_charges_posted,
            ],
            'items' => $folio->items->sortBy('occurred_at')->map(fn ($i) => [
                'id' => $i->id,
                'kind' => $i->kind,
                'description' => $i->description,
                'amount_cents' => $i->amount_cents,
                'business_date' => $i->business_date->toDateString(),
                'occurred_at' => $i->occurred_at?->toIso8601String(),
            ])->values(),
            'payments' => $folio->payments->sortBy('occurred_at')->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount_cents' => $p->amount_cents,
                'receipt_no' => $p->receipt_no,
                'business_date' => $p->business_date->toDateString(),
                'occurred_at' => $p->occurred_at?->toIso8601String(),
            ])->values(),
            'adjustments' => $folio->adjustments->sortBy('created_at')->map(fn ($a) => [
                'id' => $a->id,
                'adjustment_no' => $a->adjustment_no,
                'kind' => $a->kind,
                'amount_cents' => $a->amount_cents,
                'affects_revenue' => $a->affects_revenue,
                'reason' => $a->reason,
                'business_date' => $a->business_date->toDateString(),
                'original_business_date' => $a->original_business_date->toDateString(),
                'original_item_id' => $a->original_item_id,
                'original_payment_id' => $a->original_payment_id,
                'created_by' => $a->created_by,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'balance' => [
                'charge_total_cents' => $charges,
                'paid_total_cents' => $paid,
                'balance_cents' => $charges - $paid,
            ],
        ]);
    }

    /**
     * 房单列表（调整单表单选择房单用）。
     */
    public function index(): JsonResponse
    {
        $folios = Folio::orderByDesc('id')->limit(100)->get()->map(fn (Folio $f) => [
            'id' => $f->id,
            'folio_no' => $f->folio_no,
            'guest_name' => $f->guest_name,
            'room_no' => $f->room_no,
            'status' => $f->status,
            'check_in_at' => $f->check_in_at?->toIso8601String(),
        ]);

        return response()->json(['folios' => $folios]);
    }
}
