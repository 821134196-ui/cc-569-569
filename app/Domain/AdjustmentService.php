<?php

namespace App\Domain;

use App\Models\Adjustment;
use App\Models\Folio;
use App\Models\FolioItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 调整单：封账后一切账务变动的唯一入口。
 * 调整单落在当前开放营业日，关联原房账条目与差额原因，
 * 同时在房账上生成一条 type=adjustment 的条目，使房单余额保持正确。
 */
class AdjustmentService
{
    public function __construct(private BusinessDayService $days) {}

    /**
     * @param  int  $amountCents  带符号金额（分）：减少应收为负，增加应收为正；
     *                            迟到收款为负（收款方向，冲减余额）。
     */
    public function create(
        Folio $folio,
        string $type,
        int $amountCents,
        string $reasonCode,
        string $reason,
        User $creator,
        ?FolioItem $originalItem = null,
        ?string $method = null,
    ): Adjustment {
        if ($originalItem !== null && $originalItem->folio_id !== $folio->id) {
            throw new \InvalidArgumentException('原条目不属于该房单');
        }

        return DB::transaction(function () use ($folio, $type, $amountCents, $reasonCode, $reason, $creator, $originalItem, $method) {
            $openDay = $this->days->currentOpenDay();

            $adjustment = Adjustment::create([
                'adjustment_no' => $this->nextAdjustmentNo($openDay->date->toDateString()),
                'business_date' => $openDay->date->toDateString(),
                'folio_id' => $folio->id,
                'original_item_id' => $originalItem?->id,
                'type' => $type,
                'amount_cents' => $amountCents,
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'created_by' => $creator->id,
            ]);

            // 调整对应的房账条目：落在当前开放营业日，保持房单余额正确。
            $folio->items()->create([
                'type' => FolioItem::TYPE_ADJUSTMENT,
                'description' => "调整单 {$adjustment->adjustment_no}：{$reason}",
                'amount_cents' => $amountCents,
                'business_date' => $openDay->date->toDateString(),
                'posted_at' => now(),
                'status' => FolioItem::STATUS_SETTLED,
                'method' => $method,
                'adjustment_id' => $adjustment->id,
            ]);

            return $adjustment->fresh(['originalItem', 'ledgerItem']);
        });
    }

    private function nextAdjustmentNo(string $date): string
    {
        $seq = Adjustment::where('business_date', $date)->count() + 1;

        return 'ADJ-'.$date.'-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
