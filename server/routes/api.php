<?php

use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BusinessDayController;
use App\Http\Controllers\FolioController;
use App\Http\Controllers\SimulationController;
use App\Services\Contracts\RoomStatusProvider;
use Illuminate\Support\Facades\Route;

// 夜审
Route::get('/audit/current', [AuditController::class, 'current']);
Route::post('/audit/post-room-charges', [AuditController::class, 'postRoomCharges']);
Route::post('/audit/{date}/folios/{folio}/check', [AuditController::class, 'check']);
Route::delete('/audit/{date}/folios/{folio}/check', [AuditController::class, 'uncheck']);
Route::post('/audit/{date}/close', [AuditController::class, 'close']);

// 营业日与快照
Route::get('/days', [BusinessDayController::class, 'index']);
Route::get('/days/{date}', [BusinessDayController::class, 'show']);

// 调整单
Route::get('/adjustments', [AdjustmentController::class, 'index']);
Route::post('/adjustments', [AdjustmentController::class, 'store']);

// 房单
Route::get('/folios', [FolioController::class, 'index']);
Route::get('/folios/{id}', [FolioController::class, 'show']);

// 场景模拟（驱动演示与验收）
Route::post('/simulate/check-in', [SimulationController::class, 'checkIn']);
Route::post('/simulate/addon', [SimulationController::class, 'addon']);
Route::post('/simulate/payment', [SimulationController::class, 'payment']);
Route::post('/simulate/late-checkout', [SimulationController::class, 'lateCheckout']);
Route::post('/simulate/check-out', [SimulationController::class, 'checkOut']);

// 本地模拟房态接口
Route::get('/mock/rooms', fn (RoomStatusProvider $rooms) => response()->json(['rooms' => $rooms->rooms()]));
