<?php

namespace App\Http\Controllers;

use App\Domain\FolioService;
use App\Models\Folio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolioController extends Controller
{
    public function __construct(private FolioService $folios) {}

    /** 房单详情：全部条目（含 pending）+ 调整单。 */
    public function show(Folio $folio): JsonResponse
    {
        $folio->load(['items.adjustment', 'adjustments.creator', 'stay']);

        return response()->json([
            'folio_no' => $folio->folio_no,
            'guest_name' => $folio->guest_name,
            'room_no' => $folio->room_no,
            'status' => $folio->status,
            'balance_cents' => $folio->balanceCents(),
            'stay' => $folio->stay ? [
                'check_in_at' => $folio->stay->check_in_at->toIso8601String(),
                'check_out_at' => $folio->stay->check_out_at?->toIso8601String(),
                'status' => $folio->stay->status,
            ] : null,
            'items' => $folio->items->sortBy('posted_at')->map(fn ($i) => [
                'id' => $i->id,
                'type' => $i->type,
                'description' => $i->description,
                'amount_cents' => $i->amount_cents,
                'business_date' => $i->business_date->toDateString(),
                'posted_at' => $i->posted_at->toIso8601String(),
                'status' => $i->status,
                'method' => $i->method,
                'adjustment_no' => $i->adjustment?->adjustment_no,
            ])->values(),
            'adjustments' => $folio->adjustments->map(fn ($a) => [
                'adjustment_no' => $a->adjustment_no,
                'business_date' => $a->business_date->toDateString(),
                'type' => $a->type,
                'amount_cents' => $a->amount_cents,
                'reason' => $a->reason,
                'created_by' => $a->creator?->name,
            ])->values(),
        ]);
    }

    /** 加购。 */
    public function addAddon(Request $request, Folio $folio): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:100',
            'amount_cents' => 'required|integer|min:1',
        ]);

        $item = $this->folios->postAddon($folio, $data['description'], $data['amount_cents']);

        return response()->json(['item_id' => $item->id, 'business_date' => $item->business_date->toDateString()], 201);
    }

    /** 退款。 */
    public function addRefund(Request $request, Folio $folio): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:100',
            'amount_cents' => 'required|integer|min:1',
        ]);

        $item = $this->folios->postRefund($folio, $data['description'], $data['amount_cents']);

        return response()->json(['item_id' => $item->id, 'business_date' => $item->business_date->toDateString()], 201);
    }
}
