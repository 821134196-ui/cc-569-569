<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\FolioItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 调整单（人工）：房费更正、加购更正。
 * 调整金额计入当前开放营业日，同时记录原营业日与原交易，
 * 供财务从调整金额追到原交易。
 */
class AdjustmentService
{
    public function __construct(private BusinessDayResolver $resolver) {}

    public function create(
        Folio $folio,
        string $kind,
        int $amountCents,
        string $reason,
        ?int $originalItemId,
        string $createdBy,
    ): Adjustment {
        if (! in_array($kind, [Adjustment::KIND_ROOM_CORRECTION, Adjustment::KIND_ADDON_CORRECTION], true)) {
            throw ValidationException::withMessages(['kind' => '人工调整单仅支持房费更正 / 加购更正']);
        }

        return DB::transaction(function () use ($folio, $kind, $amountCents, $reason, $originalItemId, $createdBy) {
            // 更正必须关联原账目项：财务可沿调整单追到原交易，
            // 原归属日 = 原交易实际落账的营业日
            $originalItem = FolioItem::where('folio_id', $folio->id)->find($originalItemId);
            if ($originalItem === null) {
                throw ValidationException::withMessages([
                    'original_item_id' => '更正类调整单必须关联该房单的原账目项',
                ]);
            }

            $originalDate = $originalItem->business_date->toDateString();
            $day = $this->resolver->currentOpenDay();

            return Adjustment::create([
                'adjustment_no' => 'ADJ-'.now()->format('Ymd').'-'.str_pad((string) (Adjustment::max('id') + 1), 5, '0', STR_PAD_LEFT),
                'folio_id' => $folio->id,
                'business_date' => $day->date->toDateString(),
                'original_business_date' => $originalDate,
                'kind' => $kind,
                'amount_cents' => $amountCents,
                'affects_revenue' => true,
                'reason' => $reason,
                'original_item_id' => $originalItem?->id,
                'created_by' => $createdBy,
            ]);
        });
    }
}
