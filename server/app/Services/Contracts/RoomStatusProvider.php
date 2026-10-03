<?php

namespace App\Services\Contracts;

/**
 * 房态接口 —— 本地模拟实现见 MockRoomStatusProvider。
 */
interface RoomStatusProvider
{
    /**
     * 返回全部房间状态：[['room_no'=>..., 'status'=>..., 'folio_no'=>...], ...]
     * status: occupied | vacant | due_out
     */
    public function rooms(): array;
}
