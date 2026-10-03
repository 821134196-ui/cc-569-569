<?php

namespace App\Domain;

use App\Domain\Exceptions\NoOpenBusinessDayException;
use App\Models\BusinessDay;
use Carbon\Carbon;

/**
 * 营业日归属规则：
 *  - 房费：归属"住宿夜"开始的日期（跨午夜入住 23:50 入住，房费归入住当天，
 *    即使过账动作发生在次日 00:10）。
 *  - 加购 / 延迟退房费 / 退款：归属实际发生（过账）时所在的开放营业日。
 *  - 收款：归属发起收款时的开放营业日；回执晚到且原营业日已封账时，
 *    原条目不动，以调整单落在回执到达时的开放营业日。
 *  - 调整单：永远落在创建时的开放营业日。
 */
class BusinessDayService
{
    /** 当前开放的营业日（正常流程下同一时间只有一个）。 */
    public function currentOpenDay(): BusinessDay
    {
        $day = BusinessDay::where('status', BusinessDay::STATUS_OPEN)
            ->orderBy('date')
            ->first();

        if (! $day) {
            throw new NoOpenBusinessDayException;
        }

        return $day;
    }

    public function findByDate(string $date): ?BusinessDay
    {
        return BusinessDay::where('date', $date)->first();
    }

    public function isClosed(string $date): bool
    {
        $day = $this->findByDate($date);

        return $day !== null && ! $day->isOpen();
    }

    /**
     * 房费的归属营业日 = 住宿夜开始的日期。
     * 例如 2026-10-03 23:50 入住，第一夜归属 2026-10-03。
     */
    public function nightDateFor(Carbon $checkInAt, int $nightIndex = 0): string
    {
        return $checkInAt->copy()->startOfDay()->addDays($nightIndex)->toDateString();
    }

    /** 确保某日营业日存在（不存在则以 open 状态创建）。 */
    public function ensureExists(string $date): BusinessDay
    {
        return BusinessDay::firstOrCreate(['date' => $date], ['status' => BusinessDay::STATUS_OPEN]);
    }
}
