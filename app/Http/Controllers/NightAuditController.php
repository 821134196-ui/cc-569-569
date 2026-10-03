<?php

namespace App\Http\Controllers;

use App\Domain\BusinessDayService;
use App\Domain\Exceptions\DayAlreadyClosedException;
use App\Domain\Exceptions\UnverifiedFoliosException;
use App\Domain\NightAuditService;
use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NightAuditController extends Controller
{
    public function __construct(
        private NightAuditService $audit,
        private BusinessDayService $days,
    ) {}

    /** 夜审页面：当前开放营业日的汇总与逐房单明细。 */
    public function current(): JsonResponse
    {
        return response()->json($this->audit->summary($this->days->currentOpenDay()));
    }

    /** 指定营业日的夜审数据（已封账日返回快照视图）。 */
    public function show(string $date): JsonResponse
    {
        $day = BusinessDay::with('snapshot.closer')->where('date', $date)->firstOrFail();

        return response()->json($this->audit->summary($day));
    }

    /** 核对房单（仅夜审员）。 */
    public function verifyFolio(Request $request, Folio $folio): JsonResponse
    {
        $actor = $this->requireAuditor($request);
        $day = $this->days->currentOpenDay();

        $this->audit->verifyFolio($day, $folio, $actor);

        return response()->json($this->audit->summary($day));
    }

    /**
     * 封账（仅夜审员）。请求携带所操作的营业日 id：
     * 两个夜审员同时提交同一营业日时，只有一个成功，另一个得到 409。
     */
    public function close(Request $request): JsonResponse
    {
        $actor = $this->requireAuditor($request);
        $data = $request->validate(['business_day_id' => 'required|exists:business_days,id']);
        $day = BusinessDay::findOrFail($data['business_day_id']);

        try {
            $snapshot = $this->audit->close($day, $actor);
        } catch (DayAlreadyClosedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (UnverifiedFoliosException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'unverified_folios' => $e->unverifiedFolioNos,
            ], 422);
        }

        return response()->json([
            'message' => "营业日 {$snapshot->business_date->toDateString()} 已封账",
            'snapshot' => $this->audit->snapshotArray($snapshot),
        ]);
    }

    private function requireAuditor(Request $request): User
    {
        $actor = $this->actor($request);
        if (! $actor->isAuditor()) {
            throw new HttpException(403, '仅夜审员可执行此操作');
        }

        return $actor;
    }
}
