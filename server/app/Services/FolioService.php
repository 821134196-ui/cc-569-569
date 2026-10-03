<?php

namespace App\Services;

use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\FolioItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 房单与入住。
 *
 * 房费按住宿夜生成：第 i 晚房费归属营业日 = 入住自然归属日 + i 天。
 * 跨午夜入住（凌晨 0–6 点）自然归属"昨夜"营业日。
 * 入住时只过账第 0 晚；后续住宿夜由夜审"过账当夜房费"逐夜生成，
 * 与真实 PMS 的夜审过账流程一致。若某住宿夜的营业日已封账，
 * ChargeService 会自动迟到落账并生成调整单。
 */
class FolioService
{
    public function __construct(
        private BusinessDayResolver $resolver,
        private ChargeService $charges,
    ) {}

    public function checkIn(
        string $guestName,
        string $roomNo,
        CarbonInterface $checkInAt,
        int $nights,
        int $roomRateCents,
        ?string $createdBy = null,
    ): Folio {
        return DB::transaction(function () use ($guestName, $roomNo, $checkInAt, $nights, $roomRateCents, $createdBy) {
            $firstNight = $this->resolver->roomNightDate($checkInAt);

            $folio = Folio::create([
                'folio_no' => 'F'.$firstNight->format('Ymd').'-'.str_pad((string) (Folio::max('id') + 1), 4, '0', STR_PAD_LEFT),
                'guest_name' => $guestName,
                'room_no' => $roomNo,
                'check_in_at' => $checkInAt,
                'planned_check_out_at' => $firstNight->addDays($nights)->setTime(12, 0),
                'nights' => $nights,
                'room_rate_cents' => $roomRateCents,
                'room_charges_posted' => 0,
                'status' => Folio::STATUS_OPEN,
            ]);

            $this->postNightCharge($folio, $createdBy);

            return $folio->refresh();
        });
    }

    /**
     * 为在店房单过账截至 $day（含）的所有未过账住宿夜房费。
     * 返回新生成的账目项数量。
     */
    public function postDueRoomCharges(BusinessDay $day, ?string $createdBy = null): int
    {
        $posted = 0;
        $folios = Folio::where('status', Folio::STATUS_OPEN)
            ->whereColumn('room_charges_posted', '<', 'nights')
            ->get();

        foreach ($folios as $folio) {
            while ($folio->room_charges_posted < $folio->nights) {
                $nightDate = $this->resolver->roomNightDate($folio->check_in_at, $folio->room_charges_posted);
                if ($nightDate->gt($day->date)) {
                    break; // 该住宿夜还没到，不提前过账
                }
                $this->postNightCharge($folio, $createdBy);
                $posted++;
            }
        }

        return $posted;
    }

    /**
     * 过账下一住宿夜房费（归属由住宿夜日期决定，可能迟到落账）。
     */
    private function postNightCharge(Folio $folio, ?string $createdBy = null): void
    {
        $nightIndex = $folio->room_charges_posted;
        $nightDate = $this->resolver->roomNightDate($folio->check_in_at, $nightIndex);

        $this->charges->postItem(
            $folio,
            FolioItem::KIND_ROOM,
            '房费 '.$nightDate->toDateString().' 夜',
            $folio->room_rate_cents,
            $nightDate->setTime(21, 0),
            $createdBy,
        );

        $folio->increment('room_charges_posted');
    }

    /**
     * 延迟退房补收：按实际补收时刻入账，归属按统一规则解析。
     */
    public function chargeLateCheckout(Folio $folio, int $amountCents, CarbonInterface $occurredAt, ?string $createdBy = null): FolioItem
    {
        return $this->charges->postItem(
            $folio,
            FolioItem::KIND_ROOM,
            '延迟退房补收',
            $amountCents,
            $occurredAt,
            $createdBy,
        );
    }
}
