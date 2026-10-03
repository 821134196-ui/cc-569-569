<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\Folio;
use App\Services\AdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $adjustments) {}

    /**
     * 调整单流水（可按落账日 / 原归属日 / 房单过滤）。
     */
    public function index(Request $request): JsonResponse
    {
        $query = Adjustment::with(['folio', 'originalItem', 'originalPayment'])->orderByDesc('created_at');

        if ($request->filled('business_date')) {
            $query->where('business_date', $request->input('business_date'));
        }
        if ($request->filled('original_business_date')) {
            $query->where('original_business_date', $request->input('original_business_date'));
        }
        if ($request->filled('folio_id')) {
            $query->where('folio_id', $request->input('folio_id'));
        }

        return response()->json([
            'adjustments' => $query->get()->map(fn (Adjustment $a) => [
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
                'original_item' => $a->originalItem ? [
                    'description' => $a->originalItem->description,
                    'amount_cents' => $a->originalItem->amount_cents,
                    'business_date' => $a->originalItem->business_date->toDateString(),
                ] : null,
                'original_payment' => $a->originalPayment ? [
                    'method' => $a->originalPayment->method,
                    'amount_cents' => $a->originalPayment->amount_cents,
                    'receipt_no' => $a->originalPayment->receipt_no,
                    'business_date' => $a->originalPayment->business_date->toDateString(),
                ] : null,
                'created_by' => $a->created_by,
                'created_at' => $a->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * 人工调整单：房费更正 / 加购更正。金额计入当前开放营业日。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folio_id' => 'required|integer|exists:folios,id',
            'kind' => 'required|string|in:room_correction,addon_correction',
            'amount_cents' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:255',
            'original_item_id' => 'required|integer|exists:folio_items,id',
            'created_by' => 'required|string|max:64',
        ]);

        $adjustment = $this->adjustments->create(
            Folio::findOrFail($validated['folio_id']),
            $validated['kind'],
            (int) $validated['amount_cents'],
            $validated['reason'],
            $validated['original_item_id'] ?? null,
            $validated['created_by'],
        );

        return response()->json([
            'id' => $adjustment->id,
            'adjustment_no' => $adjustment->adjustment_no,
            'business_date' => $adjustment->business_date->toDateString(),
            'original_business_date' => $adjustment->original_business_date->toDateString(),
        ], 201);
    }
}
