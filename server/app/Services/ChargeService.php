<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Folio;
use App\Models\FolioItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 费用入账：房费与加购项目。
 * 若自然归属营业日已封账，账目落到当前开放营业日，
 * 并自动生成 late_charge 调整单关联原营业日 —— 昨日数字保持不动。
 */
class ChargeService
{
    public function __construct(private BusinessDayResolver $resolver) {}

    public function postItem(
        Folio $folio,
        string $kind,
        string $description,
        int $amountCents,
        CarbonInterface $occurredAt,
        ?string $createdBy = null,
    ): FolioItem {
        return DB::transaction(function () use ($folio, $kind, $description, $amountCents, $occurredAt, $createdBy) {
            [$day, $natural, $isLate] = $this->resolver->postingDate($occurredAt);

            $item = FolioItem::create([
                'folio_id' => $folio->id,
                'kind' => $kind,
                'description' => $description,
                'amount_cents' => $amountCents,
                'business_date' => $day->date->toDateString(),
                'occurred_at' => $occurredAt,
            ]);

            if ($isLate) {
                Adjustment::create([
                    'adjustment_no' => $this->nextAdjustmentNo(),
                    'folio_id' => $folio->id,
                    'business_date' => $day->date->toDateString(),
                    'original_business_date' => $natural->toDateString(),
                    'kind' => Adjustment::KIND_LATE_CHARGE,
                    'amount_cents' => $amountCents,
                    'affects_revenue' => false, // 金额已由账目项计入落账日，此处仅作归属说明
                    'reason' => sprintf(
                        '%s（实际发生 %s，自然归属营业日 %s 已封账，落入 %s）',
                        $description,
                        $occurredAt->format('Y-m-d H:i'),
                        $natural->toDateString(),
                        $day->date->toDateString()
                    ),
                    'original_item_id' => $item->id,
                    'created_by' => $createdBy ?? 'system',
                ]);
            }

            return $item;
        });
    }

    private function nextAdjustmentNo(): string
    {
        return 'ADJ-'.now()->format('Ymd').'-'.str_pad((string) (Adjustment::max('id') + 1), 5, '0', STR_PAD_LEFT);
    }
}
