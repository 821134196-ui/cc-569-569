<?php

namespace App\Services;

use App\Models\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 营业日归属规则。
 *
 * 核心规则：交易的自然归属营业日 = (发生时间 - 切分时刻) 的日期。
 * 默认切分时刻为清晨 6 点，因此：
 *   - 2026-10-01 23:50 入住 → 营业日 2026-10-01
 *   - 2026-10-02 00:30 入住 → 营业日 2026-10-01（跨午夜仍归"昨夜"）
 *   - 2026-10-02 14:00 消费 → 营业日 2026-10-02
 *
 * 落账规则：若自然归属日已封账，绝不允许改昨日数字，交易落到当前
 * 开放营业日，并由调用方生成调整单关联原营业日。
 */
class BusinessDayResolver
{
    public function rolloverHour(): int
    {
        return (int) config('hotel.day_rollover_hour', 6);
    }

    /**
     * 自然归属营业日（不考虑封账）。
     */
    public function naturalDate(CarbonInterface $occurredAt): CarbonImmutable
    {
        return CarbonImmutable::instance($occurredAt)
            ->subHours($this->rolloverHour())
            ->startOfDay();
    }

    /**
     * 某住宿夜（以入住当晚日期标识）归属的营业日。
     * 住宿夜 D 的房费归属营业日 D。
     */
    public function roomNightDate(CarbonInterface $checkInAt, int $nightIndex = 0): CarbonImmutable
    {
        return $this->naturalDate($checkInAt)->addDays($nightIndex);
    }

    /**
     * 落账归属：返回 [落账营业日, 自然归属日, 是否迟到落账]。
     * 自然日未封账 → 直接落账；已封账 → 落到当前开放营业日。
     */
    public function postingDate(CarbonInterface $occurredAt): array
    {
        $natural = $this->naturalDate($occurredAt);
        $naturalDay = BusinessDay::where('date', $natural->toDateString())->first();

        if ($naturalDay === null) {
            $naturalDay = $this->ensureOpenDay($natural->toDateString());

            return [$naturalDay, $natural, false];
        }

        if ($naturalDay->isOpen()) {
            return [$naturalDay, $natural, false];
        }

        // 自然归属日已封账：落到当前开放营业日（迟到落账）
        return [$this->currentOpenDay(), $natural, true];
    }

    /**
     * 确保某日存在开放的营业日记录（已封账则原样返回，绝不重开）。
     */
    public function ensureOpenDay(string $date): BusinessDay
    {
        return BusinessDay::firstOrCreate(['date' => $date], ['status' => BusinessDay::STATUS_OPEN]);
    }

    /**
     * 当前开放营业日：取最晚的开放日；不存在时在
     * "最后一个已封账日的次日"与"今天的自然归属日"中取较晚者创建。
     */
    public function currentOpenDay(): BusinessDay
    {
        $open = BusinessDay::where('status', BusinessDay::STATUS_OPEN)
            ->orderByDesc('date')
            ->first();

        if ($open !== null) {
            return $open;
        }

        $lastClosed = BusinessDay::where('status', BusinessDay::STATUS_CLOSED)
            ->max('date');

        $candidates = [$this->naturalDate(CarbonImmutable::now())->toDateString()];
        if ($lastClosed !== null) {
            $candidates[] = CarbonImmutable::parse($lastClosed)->addDay()->toDateString();
        }

        return $this->ensureOpenDay(max($candidates));
    }
}
