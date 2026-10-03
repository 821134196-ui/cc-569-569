<?php

namespace App\Services\Mock;

use App\Models\Folio;
use App\Services\Contracts\RoomStatusProvider;

/**
 * 本地模拟房态接口：固定 8 间房，占用情况由在店房单推导，
 * 模拟 PMS 房态系统的只读查询。
 */
class MockRoomStatusProvider implements RoomStatusProvider
{
    private const ROOMS = ['301', '302', '303', '401', '402', '403', '501', '502'];

    public function rooms(): array
    {
        $occupied = Folio::where('status', Folio::STATUS_OPEN)->get()->keyBy('room_no');

        return array_map(function (string $roomNo) use ($occupied) {
            $folio = $occupied->get($roomNo);

            if ($folio === null) {
                return ['room_no' => $roomNo, 'status' => 'vacant', 'folio_no' => null];
            }

            $dueOut = $folio->planned_check_out_at !== null
                && $folio->planned_check_out_at->isToday();

            return [
                'room_no' => $roomNo,
                'status' => $dueOut ? 'due_out' : 'occupied',
                'folio_no' => $folio->folio_no,
            ];
        }, self::ROOMS);
    }
}
