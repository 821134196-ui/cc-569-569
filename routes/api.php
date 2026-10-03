<?php

use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FolioController;
use App\Http\Controllers\NightAuditController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StayController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\ResolveDemoUser;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveDemoUser::class)->group(function () {
    // 用户（演示用身份切换）
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/me', [UserController::class, 'me']);

    // 夜审
    Route::get('/audit/current', [NightAuditController::class, 'current']);
    Route::get('/audit/days/{date}', [NightAuditController::class, 'show']);
    Route::post('/audit/folios/{folio}/verify', [NightAuditController::class, 'verifyFolio']);
    Route::post('/audit/close', [NightAuditController::class, 'close']);

    // 房态模拟
    Route::get('/stays', [StayController::class, 'index']);
    Route::post('/stays', [StayController::class, 'store']);
    Route::post('/stays/{stay}/room-charges', [StayController::class, 'postRoomCharge']);
    Route::post('/stays/{stay}/checkout', [StayController::class, 'checkOut']);

    // 房单
    Route::get('/folios/{folio}', [FolioController::class, 'show']);
    Route::post('/folios/{folio}/addons', [FolioController::class, 'addAddon']);
    Route::post('/folios/{folio}/refunds', [FolioController::class, 'addRefund']);

    // 支付模拟
    Route::get('/payments/pending', [PaymentController::class, 'pending']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::post('/payments/settle-pending', [PaymentController::class, 'settlePending']);

    // 调整单
    Route::get('/adjustments', [AdjustmentController::class, 'index']);
    Route::post('/adjustments', [AdjustmentController::class, 'store']);

    // 财务
    Route::get('/finance/days', [FinanceController::class, 'days']);
    Route::get('/finance/days/{date}', [FinanceController::class, 'dayReport']);
    Route::get('/finance/reconciliation', [FinanceController::class, 'reconciliation']);
});
