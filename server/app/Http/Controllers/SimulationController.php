<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\FolioItem;
use App\Services\ChargeService;
use App\Services\FolioService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 场景模拟：驱动跨午夜入住、延迟退房、晚到支付等演示场景，
 * 全部走与正式流程相同的服务层，归属规则一致。
 */
class SimulationController extends Controller
{
    public function __construct(
        private FolioService $folios,
        private ChargeService $charges,
        private PaymentService $payments,
    ) {}

    /**
     * 模拟入住（可指定任意入住时刻，用于跨午夜场景）。
     */
    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:64',
            'room_no' => 'required|string|max:16',
            'check_in_at' => 'required|date',
            'nights' => 'required|integer|min:1|max:14',
            'room_rate_cents' => 'required|integer|min:0',
        ]);

        $folio = $this->folios->checkIn(
            $validated['guest_name'],
            $validated['room_no'],
            CarbonImmutable::parse($validated['check_in_at']),
            (int) $validated['nights'],
            (int) $validated['room_rate_cents'],
            '场景模拟',
        );

        return response()->json([
            'folio_id' => $folio->id,
            'folio_no' => $folio->folio_no,
            'items' => $folio->items()->get(['description', 'amount_cents', 'business_date', 'occurred_at']),
        ], 201);
    }

    /**
     * 模拟加购消费。
     */
    public function addon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folio_id' => 'required|integer|exists:folios,id',
            'description' => 'required|string|max:128',
            'amount_cents' => 'required|integer|min:1',
            'occurred_at' => 'required|date',
        ]);

        $item = $this->charges->postItem(
            Folio::findOrFail($validated['folio_id']),
            FolioItem::KIND_ADDON,
            $validated['description'],
            (int) $validated['amount_cents'],
            CarbonImmutable::parse($validated['occurred_at']),
            '场景模拟',
        );

        return response()->json([
            'item_id' => $item->id,
            'business_date' => $item->business_date->toDateString(),
        ], 201);
    }

    /**
     * 模拟收/退款（可指定发生时刻，用于支付回执晚到场景）。
     */
    public function payment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folio_id' => 'required|integer|exists:folios,id',
            'method' => 'required|string|in:cash,card',
            'amount_cents' => 'required|integer|not_in:0',
            'occurred_at' => 'required|date',
        ]);

        $payment = $this->payments->record(
            Folio::findOrFail($validated['folio_id']),
            $validated['method'],
            (int) $validated['amount_cents'],
            CarbonImmutable::parse($validated['occurred_at']),
            '场景模拟',
        );

        return response()->json([
            'payment_id' => $payment->id,
            'receipt_no' => $payment->receipt_no,
            'business_date' => $payment->business_date->toDateString(),
        ], 201);
    }

    /**
     * 模拟延迟退房补收。
     */
    public function lateCheckout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folio_id' => 'required|integer|exists:folios,id',
            'amount_cents' => 'required|integer|min:1',
            'occurred_at' => 'required|date',
        ]);

        $item = $this->folios->chargeLateCheckout(
            Folio::findOrFail($validated['folio_id']),
            (int) $validated['amount_cents'],
            CarbonImmutable::parse($validated['occurred_at']),
            '场景模拟',
        );

        return response()->json([
            'item_id' => $item->id,
            'business_date' => $item->business_date->toDateString(),
        ], 201);
    }

    /**
     * 模拟退房。
     */
    public function checkOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folio_id' => 'required|integer|exists:folios,id',
            'occurred_at' => 'required|date',
        ]);

        $folio = Folio::findOrFail($validated['folio_id']);
        $folio->update([
            'status' => Folio::STATUS_CHECKED_OUT,
            'actual_check_out_at' => CarbonImmutable::parse($validated['occurred_at']),
        ]);

        return response()->json(['folio_id' => $folio->id, 'status' => $folio->status]);
    }
}
