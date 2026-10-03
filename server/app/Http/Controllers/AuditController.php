<?php

namespace App\Http\Controllers;

use App\Models\BusinessDay;
use App\Models\Folio;
use App\Services\BusinessDayResolver;
use App\Services\Contracts\RoomStatusProvider;
use App\Services\FolioService;
use App\Services\NightAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(
        private NightAuditService $audit,
        private BusinessDayResolver $resolver,
        private FolioService $folios,
        private RoomStatusProvider $rooms,
    ) {}

    /**
     * 当前开放营业日的夜审视图：汇总 + 房单明细 + 核对状态 + 房态交叉核对。
     */
    public function current(): JsonResponse
    {
        $day = $this->resolver->currentOpenDay();
        $summary = $this->audit->summary($day);

        // 房态交叉核对：在店但当日无账务活动的房单提示
        $activeFolioNos = $summary['folios']->map(fn ($f) => $f['folio']->folio_no);
        $roomWarnings = collect($this->rooms->rooms())
            ->filter(fn ($r) => $r['status'] !== 'vacant' && $r['folio_no'] !== null && ! $activeFolioNos->contains($r['folio_no']))
            ->values();

        return response()->json([
            'day' => $this->dayResource($day),
            'totals' => $summary['totals'],
            'folios' => $summary['folios']->map(fn ($f) => $this->folioActivityResource($f)),
            'unchecked_count' => $summary['unchecked_count'],
            'room_status' => $this->rooms->rooms(),
            'room_warnings' => $roomWarnings,
        ]);
    }

    /**
     * 过账当夜房费（夜审第一步：为在店房单生成当夜房费）。
     */
    public function postRoomCharges(Request $request): JsonResponse
    {
        $day = $this->resolver->currentOpenDay();
        abort_unless($day->isOpen(), 409, '营业日已封账');

        $posted = $this->folios->postDueRoomCharges($day, $request->input('auditor', '夜审员'));

        return response()->json(['posted' => $posted, 'date' => $day->date->toDateString()]);
    }

    public function check(Request $request, string $date, int $folioId): JsonResponse
    {
        $validated = $request->validate(['auditor' => 'required|string|max:64']);
        $day = BusinessDay::where('date', $date)->firstOrFail();
        $folio = Folio::findOrFail($folioId);

        $check = $this->audit->checkFolio($day, $folio, $validated['auditor']);

        return response()->json(['checked' => true, 'auditor' => $check->auditor, 'checked_at' => $check->checked_at]);
    }

    public function uncheck(string $date, int $folioId): JsonResponse
    {
        $day = BusinessDay::where('date', $date)->firstOrFail();
        $folio = Folio::findOrFail($folioId);

        $this->audit->uncheckFolio($day, $folio);

        return response()->json(['checked' => false]);
    }

    /**
     * 封账：并发时只有一个请求能成功，其余收到 409。
     */
    public function close(Request $request, string $date): JsonResponse
    {
        $validated = $request->validate(['auditor' => 'required|string|max:64']);
        $day = BusinessDay::where('date', $date)->firstOrFail();

        $snapshot = $this->audit->close($day, $validated['auditor']);

        return response()->json([
            'closed' => true,
            'snapshot_id' => $snapshot->id,
            'date' => $date,
            'closed_by' => $validated['auditor'],
        ]);
    }

    private function dayResource(BusinessDay $day): array
    {
        return [
            'date' => $day->date->toDateString(),
            'status' => $day->status,
            'closed_at' => $day->closed_at?->toIso8601String(),
            'closed_by' => $day->closed_by,
        ];
    }

    private function folioActivityResource(array $activity): array
    {
        $folio = $activity['folio'];

        return [
            'folio_id' => $folio->id,
            'folio_no' => $folio->folio_no,
            'guest_name' => $folio->guest_name,
            'room_no' => $folio->room_no,
            'status' => $folio->status,
            'room_cents' => $activity['room_cents'],
            'addon_cents' => $activity['addon_cents'],
            'paid_cents' => $activity['paid_cents'],
            'checked' => $activity['checked'],
            'checked_by' => $activity['checked_by'],
            'checked_at' => $activity['checked_at']?->toIso8601String(),
            'items' => $activity['items']->map(fn ($i) => [
                'id' => $i->id,
                'kind' => $i->kind,
                'description' => $i->description,
                'amount_cents' => $i->amount_cents,
                'occurred_at' => $i->occurred_at?->toIso8601String(),
                'business_date' => $i->business_date->toDateString(),
            ])->values(),
            'payments' => $activity['payments']->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount_cents' => $p->amount_cents,
                'receipt_no' => $p->receipt_no,
                'occurred_at' => $p->occurred_at?->toIso8601String(),
                'business_date' => $p->business_date->toDateString(),
            ])->values(),
            'adjustments' => $activity['adjustments']->map(fn ($a) => [
                'id' => $a->id,
                'adjustment_no' => $a->adjustment_no,
                'kind' => $a->kind,
                'amount_cents' => $a->amount_cents,
                'affects_revenue' => $a->affects_revenue,
                'reason' => $a->reason,
                'original_business_date' => $a->original_business_date->toDateString(),
            ])->values(),
        ];
    }
}
