<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Folio;
use App\Models\Payment;
use App\Services\Contracts\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 收退款登记。
 * 钱实际到账的营业日 = 自然归属日；若该日已封账（支付回执晚到），
 * 收款落到当前开放营业日，并自动生成 late_payment 调整单关联
 * 原营业日 —— 差额通过调整单说明，绝不修改昨日数字。
 */
class PaymentService
{
    public function __construct(
        private BusinessDayResolver $resolver,
        private PaymentGateway $gateway,
    ) {}

    public function record(
        Folio $folio,
        string $method,
        int $amountCents, // 正=收款 负=退款
        CarbonInterface $occurredAt,
        ?string $createdBy = null,
    ): Payment {
        return DB::transaction(function () use ($folio, $method, $amountCents, $occurredAt, $createdBy) {
            [$day, $natural, $isLate] = $this->resolver->postingDate($occurredAt);

            $payment = new Payment([
                'folio_id' => $folio->id,
                'method' => $method,
                'amount_cents' => $amountCents,
                'business_date' => $day->date->toDateString(),
                'occurred_at' => $occurredAt,
                'receipt_no' => 'PENDING',
            ]);
            $payment->save();
            $payment->receipt_no = $this->gateway->settle($payment);
            $payment->save();

            if ($isLate) {
                Adjustment::create([
                    'adjustment_no' => 'ADJ-'.now()->format('Ymd').'-'.str_pad((string) (Adjustment::max('id') + 1), 5, '0', STR_PAD_LEFT),
                    'folio_id' => $folio->id,
                    'business_date' => $day->date->toDateString(),
                    'original_business_date' => $natural->toDateString(),
                    'kind' => Adjustment::KIND_LATE_PAYMENT,
                    'amount_cents' => $amountCents,
                    'affects_revenue' => false, // 现金已计入落账日收款，此单仅说明钱的原归属
                    'reason' => sprintf(
                        '支付回执晚到：%s %s %.2f 元（实际发生 %s，原归属营业日 %s 已封账）',
                        $method === 'cash' ? '现金' : '银行卡',
                        $amountCents >= 0 ? '收款' : '退款',
                        abs($amountCents) / 100,
                        $occurredAt->format('Y-m-d H:i'),
                        $natural->toDateString()
                    ),
                    'original_payment_id' => $payment->id,
                    'created_by' => $createdBy ?? 'system',
                ]);
            }

            return $payment;
        });
    }
}
