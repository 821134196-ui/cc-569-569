<?php

namespace App\Http\Controllers;

use App\Domain\FolioService;
use App\Models\Stay;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 房态接口的本地模拟：入住、退房、过房费。
 */
class StayController extends Controller
{
    public function __construct(private FolioService $folios) {}

    public function index(): JsonResponse
    {
        $stays = Stay::with('folio')->latest('id')->limit(50)->get()->map(fn (Stay $s) => [
            'id' => $s->id,
            'room_no' => $s->room_no,
            'guest_name' => $s->guest_name,
            'check_in_at' => $s->check_in_at->toIso8601String(),
            'check_out_at' => $s->check_out_at?->toIso8601String(),
            'status' => $s->status,
            'folio_id' => $s->folio?->id,
            'folio_no' => $s->folio?->folio_no,
            'balance_cents' => $s->folio?->balanceCents(),
        ]);

        return response()->json($stays);
    }

    /** 入住。check_in_at 可显式传入以模拟跨午夜入住（如 23:50）。 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_no' => 'required|string|max:20',
            'guest_name' => 'required|string|max:100',
            'check_in_at' => 'nullable|date',
        ]);

        $stay = $this->folios->checkIn(
            $data['room_no'],
            $data['guest_name'],
            isset($data['check_in_at']) ? Carbon::parse($data['check_in_at']) : now(),
        );

        return response()->json([
            'stay_id' => $stay->id,
            'folio_id' => $stay->folio->id,
            'folio_no' => $stay->folio->folio_no,
        ], 201);
    }

    /**
     * 过一晚房费。night_date 为住宿夜开始日期（归属营业日），
     * posted_at 可模拟次日凌晨过账。
     */
    public function postRoomCharge(Request $request, Stay $stay): JsonResponse
    {
        $data = $request->validate([
            'night_date' => 'required|date',
            'rate_cents' => 'required|integer|min:0',
            'posted_at' => 'nullable|date',
        ]);

        $item = $this->folios->postRoomCharge(
            $stay,
            $data['night_date'],
            $data['rate_cents'],
            isset($data['posted_at']) ? Carbon::parse($data['posted_at']) : null,
        );

        return response()->json([
            'item_id' => $item->id,
            'business_date' => $item->business_date->toDateString(),
            'posted_at' => $item->posted_at->toIso8601String(),
        ], 201);
    }

    /** 退房。12:00 后退房自动收延迟退房费（归属退房当天）。 */
    public function checkOut(Request $request, Stay $stay): JsonResponse
    {
        $data = $request->validate([
            'check_out_at' => 'nullable|date',
            'late_fee_cents' => 'nullable|integer|min:0',
        ]);

        $stay = $this->folios->checkOut(
            $stay,
            isset($data['check_out_at']) ? Carbon::parse($data['check_out_at']) : now(),
            $data['late_fee_cents'] ?? 0,
        );

        return response()->json(['stay_id' => $stay->id, 'status' => $stay->status]);
    }
}
