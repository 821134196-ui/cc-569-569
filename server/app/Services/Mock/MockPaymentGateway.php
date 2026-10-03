<?php

namespace App\Services\Mock;

use App\Models\Payment;
use App\Services\Contracts\PaymentGateway;

/**
 * 本地模拟支付网关：不发起真实扣款，回执写入本地 JSONL 文件，
 * 模拟"支付回执"这一外部凭据（包括晚到场景）。
 */
class MockPaymentGateway implements PaymentGateway
{
    public function settle(Payment $payment): string
    {
        $receiptNo = sprintf(
            'MOCK-%s-%s',
            strtoupper($payment->method === 'cash' ? 'CSH' : 'CRD'),
            str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT)
        );

        $line = json_encode([
            'receipt_no' => $receiptNo,
            'payment_id' => $payment->id,
            'folio_id' => $payment->folio_id,
            'amount_cents' => $payment->amount_cents,
            'method' => $payment->method,
            'occurred_at' => $payment->occurred_at?->toIso8601String(),
            'settled_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE);

        $dir = storage_path('app/mock');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir.'/gateway_receipts.jsonl', $line.PHP_EOL, FILE_APPEND | LOCK_EX);

        return $receiptNo;
    }
}
