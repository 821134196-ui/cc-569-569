#!/usr/bin/env bash
#
# 端到端验证脚本（需要服务已启动：./start.sh）
#
#   第一部分：营业日归属 —— 跨午夜入住 + 晚到支付回执
#   第二部分：并发封账 —— 两个夜审员同时封账，只能成功一次
#   第三部分：封账后差额核对 —— 调整单追溯原交易，汇总与流水对账
#
set -euo pipefail
cd "$(dirname "$0")/.."

BASE="${BASE:-http://127.0.0.1:8000}"
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); echo "  ✔ $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  ✘ $1"; }
check() { # check <描述> <实际> <期望>
    if [ "$2" = "$3" ]; then ok "$1（$2）"; else bad "$1：期望 $3，实际 $2"; fi
}

# JSON 取值：jget 'a.b.0.c'
jget() { python3 -c "
import json,sys
d=json.load(sys.stdin)
for k in sys.argv[1].split('.'):
    d = d[int(k)] if isinstance(d,list) else d[k]
print('' if d is None else (json.dumps(d,ensure_ascii=False) if isinstance(d,(dict,list)) else d))
" "$1"; }

api() { # api <方法> <路径> [用户ID] [JSON体]
    local method="$1" path="$2" uid="${3:-}" body="${4:-}"
    local args=(-s -X "$method" -H 'Accept: application/json')
    [ -n "$uid" ] && args+=(-H "X-User-Id: $uid")
    [ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
    curl "${args[@]}" "$BASE$path"
}

curl -sf "$BASE/api/users" > /dev/null || { echo "服务未启动，请先运行 ./start.sh"; exit 1; }

AUDITOR_A=$(api GET /api/users | python3 -c "import json,sys;print([u['id'] for u in json.load(sys.stdin) if u['name']=='夜审员甲'][0])")
AUDITOR_B=$(api GET /api/users | python3 -c "import json,sys;print([u['id'] for u in json.load(sys.stdin) if u['name']=='夜审员乙'][0])")
FINANCE=$(api GET /api/users   | python3 -c "import json,sys;print([u['id'] for u in json.load(sys.stdin) if u['role']=='finance'][0])")
FRONTDESK=$(api GET /api/users | python3 -c "import json,sys;print([u['id'] for u in json.load(sys.stdin) if u['role']=='frontdesk'][0])")

echo "=============================================================="
echo "第一部分：营业日归属（跨午夜入住 / 延迟退房 / 晚到支付回执）"
echo "=============================================================="

# --- 跨午夜入住：种子数据中张三 23:50 入住，房费次日 00:10 过账 ---
ZHANGSAN_FOLIO=$(api GET /api/stays | python3 -c "import json,sys;print([s['folio_id'] for s in json.load(sys.stdin) if s['guest_name']=='张三'][0])")
ITEM=$(api GET "/api/folios/$ZHANGSAN_FOLIO" | python3 -c "
import json,sys
d=json.load(sys.stdin)
i=[x for x in d['items'] if x['type']=='room_charge'][0]
print(i['business_date']); print(i['posted_at'])")
BIZ_DATE=$(echo "$ITEM" | sed -n 1p)
POSTED=$(echo "$ITEM" | sed -n 2p | cut -c12-13)
echo "  张三 23:50 跨午夜入住：房费归属营业日 $BIZ_DATE，过账时间为次日 ${POSTED} 点"
YESTERDAY=$(date -d "yesterday" +%F)
check "跨午夜入住的房费归属入住当晚（而非过账当天）" "$BIZ_DATE" "$YESTERDAY"
check "过账发生在午夜后（00 点）" "$POSTED" "00"

# --- 晚到支付回执：李四的 700 元刷卡回执在昨日封账后才到达 ---
PENDING_BEFORE=$(api GET /api/payments/pending | python3 -c "import json,sys;print(len(json.load(sys.stdin)))")
echo "  待到账回执 $PENDING_BEFORE 笔（李四 刷卡 ¥700.00，原属 $YESTERDAY，该日已封账）"
SETTLE=$(api POST /api/payments/settle-pending "$FRONTDESK" '{}')
VIA=$(echo "$SETTLE" | jget 'settled.0.via')
ADJ_NO=$(echo "$SETTLE" | jget 'adjustments.0')
check "回执晚到且原营业日已封账 → 走调整单（不改昨日数字）" "$VIA" "adjustment"

ADJ=$(api GET /api/adjustments | python3 -c "
import json,sys
a=[x for x in json.load(sys.stdin) if x['type']=='late_payment'][0]
print(a['business_date']); print(a['amount_cents']); print(a['original_item']['business_date'])")
ADJ_DAY=$(echo "$ADJ" | sed -n 1p); ADJ_AMT=$(echo "$ADJ" | sed -n 2p); ORIG_DAY=$(echo "$ADJ" | sed -n 3p)
TODAY=$(date +%F)
check "迟到收款调整单落在当前开放营业日" "$ADJ_DAY" "$TODAY"
check "调整金额 = -700.00 元（收款方向）" "$ADJ_AMT" "-70000"
check "调整单关联的原交易归属昨日" "$ORIG_DAY" "$YESTERDAY"

# --- 延迟退房：王五今日 14:00 退房，延迟退房费归今天 ---
WANGWU_STAY=$(api GET /api/stays | python3 -c "import json,sys;print([s['id'] for s in json.load(sys.stdin) if s['guest_name']=='王五'][0])")
api POST "/api/stays/$WANGWU_STAY/checkout" "$FRONTDESK" "{\"check_out_at\":\"${TODAY}T14:00:00\",\"late_fee_cents\":29400}" > /dev/null
WANGWU_FOLIO=$(api GET /api/stays | python3 -c "import json,sys;print([s['folio_id'] for s in json.load(sys.stdin) if s['guest_name']=='王五'][0])")
FEE_DAY=$(api GET "/api/folios/$WANGWU_FOLIO" | python3 -c "
import json,sys
d=json.load(sys.stdin)
print([i['business_date'] for i in d['items'] if i['description']=='延迟退房费'][0])")
check "延迟退房费归属退房当天" "$FEE_DAY" "$TODAY"

echo ""
echo "=============================================================="
echo "第二部分：并发封账（两个夜审员同时执行，只能封账一次）"
echo "=============================================================="

# 核对当前开放日的全部房单
CURRENT=$(api GET /api/audit/current)
DAY_ID=$(echo "$CURRENT" | jget 'day.id')
DAY_DATE=$(echo "$CURRENT" | jget 'day.date')
for FID in $(echo "$CURRENT" | python3 -c "
import json,sys
d=json.load(sys.stdin)
print(' '.join(str(f['folio_id']) for f in d['folios'] if not f['verified']))"); do
    api POST "/api/audit/folios/$FID/verify" "$AUDITOR_A" > /dev/null
done
UNVERIFIED=$(api GET /api/audit/current | jget 'unverified_count')
check "封账前全部房单已核对" "$UNVERIFIED" "0"

# 两个夜审员同时提交封账
echo "  夜审员甲、乙同时对营业日 $DAY_DATE 提交封账 ..."
RESP_A=$(mktemp); RESP_B=$(mktemp)
curl -s -o "$RESP_A" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-User-Id: $AUDITOR_A" \
    -d "{\"business_day_id\": $DAY_ID}" "$BASE/api/audit/close" > /tmp/code_a &
PID_A=$!
curl -s -o "$RESP_B" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-User-Id: $AUDITOR_B" \
    -d "{\"business_day_id\": $DAY_ID}" "$BASE/api/audit/close" > /tmp/code_b &
PID_B=$!
wait $PID_A $PID_B
CODE_A=$(cat /tmp/code_a); CODE_B=$(cat /tmp/code_b)
echo "  甲 → HTTP $CODE_A；乙 → HTTP $CODE_B"

CODES=$(printf '%s\n%s\n' "$CODE_A" "$CODE_B" | sort | tr '\n' ',' | sed 's/,$//')
check "一个成功(200)一个冲突(409)" "$CODES" "200,409"
SNAP_COUNT=$(api GET /api/finance/days | python3 -c "
import json,sys
print(len([d for d in json.load(sys.stdin) if d['date']=='$DAY_DATE' and d['status']=='closed']))")
check "营业日 $DAY_DATE 只封账一次" "$SNAP_COUNT" "1"
CLOSED_BY=$(api GET "/api/audit/days/$DAY_DATE" | jget 'snapshot.closed_by')
echo "  快照由 $CLOSED_BY 封存，另一人的请求被拒绝且未产生第二份快照"

echo ""
echo "=============================================================="
echo "第三部分：封账后的差额核对（调整单 → 原交易 → 汇总对账）"
echo "=============================================================="

# 对已封账日（昨日）的房费做更正：张三房费 588.00 → 538.00
ZHANGSAN_ROOM_ITEM=$(api GET "/api/folios/$ZHANGSAN_FOLIO" | python3 -c "
import json,sys
d=json.load(sys.stdin)
print([i['id'] for i in d['items'] if i['type']=='room_charge'][0])")
CORRECTION=$(api POST /api/adjustments "$FINANCE" "{
  \"folio_id\": $ZHANGSAN_FOLIO,
  \"original_item_id\": $ZHANGSAN_ROOM_ITEM,
  \"type\": \"room_charge_correction\",
  \"amount_cents\": -5000,
  \"reason_code\": \"rate_error\",
  \"reason\": \"房价录入错误，应为 538.00 元\"
}")
CORR_NO=$(echo "$CORRECTION" | jget 'adjustment_no')
echo "  财务创建房费更正调整单 $CORR_NO（-50.00 元，关联原房费条目 #$ZHANGSAN_ROOM_ITEM）"

REPORT=$(api GET "/api/finance/days/$YESTERDAY")
SNAP_NET=$(echo "$REPORT" | jget 'snapshot.net_cents')
ADJ_TOTAL=$(echo "$REPORT" | jget 'adjustment_total_cents')
EFF_NET=$(echo "$REPORT" | jget 'effective_net_cents')
RECONCILED=$(echo "$REPORT" | jget 'reconciled')
echo "  营业日 $YESTERDAY：快照净额 $SNAP_NET 分 + 调整流水 $ADJ_TOTAL 分 = 有效净额 $EFF_NET 分"
check "快照 + 调整流水 = 有效净额" "$((SNAP_NET + ADJ_TOTAL))" "$EFF_NET"
check "每笔调整与房账条目金额一致" "$RECONCILED" "True"

TRACE=$(echo "$REPORT" | python3 -c "
import json,sys
d=json.load(sys.stdin)
for a in d['adjustments']:
    o=a['original_item']
    print(f\"    {a['adjustment_no']} {a['type']} {a['amount_cents']/100:+.2f} 元 → 原交易 #{o['id']} {o['type']} {o['amount_cents']/100:.2f} 元（归属 {o['business_date']}）\")")
echo "  调整单追溯到原交易："
echo "$TRACE"

GLOBAL=$(api GET /api/finance/reconciliation)
TIED=$(echo "$GLOBAL" | jget 'totals_tied')
PER_ITEM=$(echo "$GLOBAL" | jget 'per_item_tied')
check "全局：调整单合计与房账调整条目合计对得上" "$TIED" "True"
check "全局：逐笔调整与流水一致" "$PER_ITEM" "True"

# 快照不可篡改：直接改库会被触发器拒绝
echo "  尝试直接篡改快照数字（应被数据库触发器拒绝）..."
PHP_BIN="$PWD/.local/bin/php"; [ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
TAMPER_OUT=$("$PHP_BIN" artisan tinker --execute="DB::table('night_audit_snapshots')->limit(1)->update(['net_cents'=>0]);" 2>&1 || true)
if echo "$TAMPER_OUT" | grep -qi 'immutable'; then
    ok "快照 UPDATE 被触发器拒绝（night audit snapshots are immutable）"
else
    bad "快照未被保护"
fi
SNAP_NET_AFTER=$(api GET "/api/finance/days/$YESTERDAY" | jget 'snapshot.net_cents')
check "篡改尝试后快照数字不变" "$SNAP_NET_AFTER" "$SNAP_NET"

echo ""
echo "=============================================================="
echo "结果：通过 $PASS 项，失败 $FAIL 项"
echo "=============================================================="
[ "$FAIL" -eq 0 ]
