<?php

namespace App\Services\Contracts;

use App\Models\Payment;

/**
 * 支付网关接口 —— 本地模拟实现见 MockPaymentGateway。
 */
interface PaymentGateway
{
    /**
     * 向网关登记一笔收/退款，返回网关回执号。
     */
    public function settle(Payment $payment): string;
}
