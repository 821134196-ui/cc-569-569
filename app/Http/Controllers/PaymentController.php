<?php

namespace App\Http\Controllers;

use App\Domain\MockPaymentGateway;
use App\Models\Folio;
use App\Models\PaymentReceipt;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 支付接口的本地模拟：发起收款、模拟回执到达。
 */
class PaymentController extends Controller
{
    public function __construct(private MockPaymentGateway $gateway) {}

    /**
     * 发起收款。delay_receipt=true 模拟回执晚到（回执 pending，
     * 之后由 settlePending 模拟网关补发回执）。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'folio_id' => 'required|exists:folios,id',
            'amount_cents' => 'required|integer|min:1',
            'method' => 'required|in:cash,card',
            'delay_receipt' => 'nullable|boolean',
            'initiated_at' => 'nullable|date',
        ]);

        $receipt = $this->gateway->pay(
            Folio::findOrFail($data['folio_id']),
            $data['amount_cents'],
            $data['method'],
            (bool) ($data['delay_receipt'] ?? false),
            isset($data['initiated_at']) ? Carbon::parse($data['initiated_at']) : null,
        );

        return response()->json([
            'receipt_no' => $receipt->receipt_no,
            'status' => $receipt->status,
            'folio_item_id' => $receipt->folio_item_id,
        ], 201);
    }

    /** 待到账回执列表。 */
    public function pending(): JsonResponse
    {
        $pending = PaymentReceipt::with('folioItem.folio')
            ->where('status', PaymentReceipt::STATUS_PENDING)
            ->get()
            ->map(fn (PaymentReceipt $r) => [
                'receipt_id' => $r->id,
                'receipt_no' => $r->receipt_no,
                'amount_cents' => $r->amount_cents,
                'folio_no' => $r->folioItem->folio->folio_no,
                'guest_name' => $r->folioItem->folio->guest_name,
                'original_business_date' => $r->folioItem->business_date->toDateString(),
            ]);

        return response()->json($pending);
    }

    /** 模拟网关回执到达：结算全部（或指定）pending 回执。 */
    public function settlePending(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receipt_id' => 'nullable|exists:payment_receipts,id',
            'settled_at' => 'nullable|date',
        ]);

        $result = $this->gateway->settlePending(
            $this->actor($request),
            $data['receipt_id'] ?? null,
            isset($data['settled_at']) ? Carbon::parse($data['settled_at']) : null,
        );

        return response()->json($result);
    }
}
