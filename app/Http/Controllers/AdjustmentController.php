<?php

namespace App\Http\Controllers;

use App\Domain\AdjustmentService;
use App\Models\Adjustment;
use App\Models\Folio;
use App\Models\FolioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $adjustments) {}

    /** 调整单列表（可按营业日过滤）。 */
    public function index(Request $request): JsonResponse
    {
        $adjustments = Adjustment::with(['folio', 'originalItem', 'creator'])
            ->when($request->query('business_date'), fn ($q, $d) => $q->where('business_date', $d))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Adjustment $a) => [
                'adjustment_no' => $a->adjustment_no,
                'business_date' => $a->business_date->toDateString(),
                'folio_no' => $a->folio->folio_no,
                'guest_name' => $a->folio->guest_name,
                'type' => $a->type,
                'amount_cents' => $a->amount_cents,
                'reason_code' => $a->reason_code,
                'reason' => $a->reason,
                'created_by' => $a->creator?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'original_item' => $a->originalItem ? [
                    'id' => $a->originalItem->id,
                    'type' => $a->originalItem->type,
                    'description' => $a->originalItem->description,
                    'amount_cents' => $a->originalItem->amount_cents,
                    'business_date' => $a->originalItem->business_date->toDateString(),
                ] : null,
            ]);

        return response()->json($adjustments);
    }

    /**
     * 手工调整单：房费更正、加购更正、退款更正等。
     * 必须关联原房账条目并填写差额原因。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'folio_id' => 'required|exists:folios,id',
            'original_item_id' => 'required|exists:folio_items,id',
            'type' => 'required|in:'.implode(',', [
                Adjustment::TYPE_ROOM_CHARGE_CORRECTION,
                Adjustment::TYPE_ADDON_CORRECTION,
                Adjustment::TYPE_REFUND_CORRECTION,
            ]),
            'amount_cents' => 'required|integer|not_in:0',
            'reason_code' => 'required|string|max:40',
            'reason' => 'required|string|max:200',
        ]);

        $folio = Folio::findOrFail($data['folio_id']);
        $originalItem = FolioItem::findOrFail($data['original_item_id']);

        $adjustment = $this->adjustments->create(
            folio: $folio,
            type: $data['type'],
            amountCents: $data['amount_cents'],
            reasonCode: $data['reason_code'],
            reason: $data['reason'],
            creator: $this->actor($request),
            originalItem: $originalItem,
        );

        return response()->json([
            'adjustment_no' => $adjustment->adjustment_no,
            'business_date' => $adjustment->business_date->toDateString(),
        ], 201);
    }
}
