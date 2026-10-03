<?php

namespace App\Domain;

use App\Models\Folio;
use App\Models\FolioItem;
use App\Models\Stay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 前台 / 房态的本地模拟：入住、退房、过房费、加购、退款。
 * 所有入账都通过这里生成房账条目，保证归属规则集中在一处。
 */
class FolioService
{
    public function __construct(private BusinessDayService $days) {}

    /** 入住：开住宿记录 + 开房单。 */
    public function checkIn(string $roomNo, string $guestName, Carbon $checkInAt): Stay
    {
        return DB::transaction(function () use ($roomNo, $guestName, $checkInAt) {
            $stay = Stay::create([
                'room_no' => $roomNo,
                'guest_name' => $guestName,
                'check_in_at' => $checkInAt,
                'status' => Stay::STATUS_IN_HOUSE,
            ]);

            $stay->folio()->create([
                'folio_no' => $this->nextFolioNo(),
                'guest_name' => $guestName,
                'room_no' => $roomNo,
                'status' => 'open',
            ]);

            return $stay->fresh('folio');
        });
    }

    /**
     * 过一晚房费。business_date = 住宿夜开始日期（$nightDate），
     * posted_at 可以晚于归属日（跨午夜过账的典型场景）。
     */
    public function postRoomCharge(Stay $stay, string $nightDate, int $rateCents, ?Carbon $postedAt = null): FolioItem
    {
        $postedAt ??= now();

        return $stay->folio->items()->create([
            'type' => FolioItem::TYPE_ROOM_CHARGE,
            'description' => "房费 {$nightDate}（{$stay->room_no}）",
            'amount_cents' => $rateCents,
            'business_date' => $nightDate,
            'posted_at' => $postedAt,
            'status' => FolioItem::STATUS_SETTLED,
        ]);
    }

    /** 加购（迷你吧、洗衣等）：归属过账时的开放营业日。 */
    public function postAddon(Folio $folio, string $description, int $amountCents, ?Carbon $postedAt = null): FolioItem
    {
        return $folio->items()->create([
            'type' => FolioItem::TYPE_ADDON,
            'description' => $description,
            'amount_cents' => $amountCents,
            'business_date' => $this->days->currentOpenDay()->date->toDateString(),
            'posted_at' => $postedAt ?? now(),
            'status' => FolioItem::STATUS_SETTLED,
        ]);
    }

    /** 退款：归属过账时的开放营业日，金额为正（冲减已收）。 */
    public function postRefund(Folio $folio, string $description, int $amountCents, ?Carbon $postedAt = null): FolioItem
    {
        return $folio->items()->create([
            'type' => FolioItem::TYPE_REFUND,
            'description' => $description,
            'amount_cents' => $amountCents,
            'business_date' => $this->days->currentOpenDay()->date->toDateString(),
            'posted_at' => $postedAt ?? now(),
            'status' => FolioItem::STATUS_SETTLED,
        ]);
    }

    /**
     * 退房。超过 12:00 退房收延迟退房费，归属退房当天（当前开放营业日）。
     */
    public function checkOut(Stay $stay, Carbon $checkOutAt, int $lateFeeCents = 0): Stay
    {
        return DB::transaction(function () use ($stay, $checkOutAt, $lateFeeCents) {
            $stay->update([
                'check_out_at' => $checkOutAt,
                'status' => Stay::STATUS_CHECKED_OUT,
            ]);

            $isLate = $checkOutAt->format('H:i') > '12:00';
            if ($isLate && $lateFeeCents > 0) {
                $stay->folio->items()->create([
                    'type' => FolioItem::TYPE_ADDON,
                    'description' => '延迟退房费',
                    'amount_cents' => $lateFeeCents,
                    'business_date' => $this->days->currentOpenDay()->date->toDateString(),
                    'posted_at' => $checkOutAt,
                    'status' => FolioItem::STATUS_SETTLED,
                ]);
            }

            return $stay->fresh();
        });
    }

    private function nextFolioNo(): string
    {
        return 'F'.str_pad((string) ((Folio::max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }
}
