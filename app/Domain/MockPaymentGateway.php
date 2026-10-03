<?php

namespace App\Domain;

use App\Models\Adjustment;
use App\Models\Folio;
use App\Models\FolioItem;
use App\Models\PaymentReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 支付网关的本地模拟。
 *  - 现金收款：回执立即到账，条目直接 settled。
 *  - 刷卡等：可模拟"回执晚到"——条目先 pending，之后由 settlePending()
 *    模拟网关回执到达。若此时原营业日已封账，不修改昨日数字，
 *    　而是自动生成"迟到收款"调整单，落在当前开放营业日。
 */
class MockPaymentGateway
{
    public function __construct(
        private BusinessDayService $days,
        private AdjustmentService $adjustments,
    ) {}

    /**
     * 发起收款。$delayReceipt=true 时模拟回执晚到（回执留 pending）。
     */
    public function pay(Folio $folio, int $amountCents, string $method, bool $delayReceipt = false, ?Carbon $initiatedAt = null): PaymentReceipt
    {
        $initiatedAt ??= now();
        $immediate = $method === FolioItem::METHOD_CASH || ! $delayReceipt;

        return DB::transaction(function () use ($folio, $amountCents, $method, $immediate, $initiatedAt) {
            $item = $folio->items()->create([
                'type' => FolioItem::TYPE_PAYMENT,
                'description' => $method === FolioItem::METHOD_CASH ? '现金收款' : '刷卡收款',
                'amount_cents' => -$amountCents, // 收款为负，冲减应收
                'business_date' => $this->days->currentOpenDay()->date->toDateString(),
                'posted_at' => $initiatedAt,
                'status' => $immediate ? FolioItem::STATUS_SETTLED : FolioItem::STATUS_PENDING,
                'method' => $method,
            ]);

            return PaymentReceipt::create([
                'receipt_no' => 'RC'.strtoupper(bin2hex(random_bytes(6))),
                'folio_item_id' => $item->id,
                'amount_cents' => $amountCents,
                'status' => $immediate ? PaymentReceipt::STATUS_SETTLED : PaymentReceipt::STATUS_PENDING,
                'settled_at' => $immediate ? $initiatedAt : null,
            ]);
        });
    }

    /**
     * 模拟网关回执到达：结算所有（或指定）pending 回执。
     * 返回处理结果，含自动生成的调整单（若有）。
     *
     * @return array{settled: array, adjustments: array}
     */
    public function settlePending(User $operator, ?int $receiptId = null, ?Carbon $settledAt = null): array
    {
        $settledAt ??= now();
        $result = ['settled' => [], 'adjustments' => []];

        $receipts = PaymentReceipt::with('folioItem.folio')
            ->where('status', PaymentReceipt::STATUS_PENDING)
            ->when($receiptId, fn ($q) => $q->where('id', $receiptId))
            ->get();

        foreach ($receipts as $receipt) {
            DB::transaction(function () use ($receipt, $settledAt, $operator, &$result) {
                $item = $receipt->folioItem;
                $originalDate = $item->business_date->toDateString();

                if (! $this->days->isClosed($originalDate)) {
                    // 原营业日仍开放：直接到账，计入原营业日。
                    $item->update(['status' => FolioItem::STATUS_SETTLED]);
                    $receipt->update([
                        'status' => PaymentReceipt::STATUS_SETTLED,
                        'settled_at' => $settledAt,
                    ]);
                    $result['settled'][] = [
                        'receipt_no' => $receipt->receipt_no,
                        'business_date' => $originalDate,
                        'via' => 'direct',
                    ];
                } else {
                    // 原营业日已封账：原条目保持 pending 不动，
                    // 以调整单把这笔钱记到当前开放营业日，并关联原条目。
                    $adjustment = $this->adjustments->create(
                        folio: $item->folio,
                        type: Adjustment::TYPE_LATE_PAYMENT,
                        amountCents: -$receipt->amount_cents, // 收款方向
                        reasonCode: 'late_payment_receipt',
                        reason: "支付回执晚到（原属 {$originalDate}，回执 {$receipt->receipt_no}）",
                        creator: $operator,
                        originalItem: $item,
                        method: $item->method,
                    );
                    $receipt->update([
                        'status' => PaymentReceipt::STATUS_SETTLED,
                        'settled_at' => $settledAt,
                    ]);
                    $result['settled'][] = [
                        'receipt_no' => $receipt->receipt_no,
                        'business_date' => $adjustment->business_date->toDateString(),
                        'via' => 'adjustment',
                    ];
                    $result['adjustments'][] = $adjustment->adjustment_no;
                }
            });
        }

        return $result;
    }
}
