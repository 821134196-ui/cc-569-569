# 酒店夜审跨日房账与差额核对系统

夜审员在每个营业日结束时核对房账并封账；前台和财务可以查清跨日交易的归属及封账后发生的调整。

- 后端：PHP 8.5 + Laravel 13 + Eloquent，SQLite（WAL）
- 前端：React 19 + Vite
- 支付网关与房态接口：本地模拟（`MockPaymentGateway`、`StayController`）

## 一条命令启动

```bash
./start.sh          # 自动准备 PHP/Composer、装依赖、建库灌数据、构建前端并启动
# 打开 http://127.0.0.1:8000
```

无系统 PHP 时会自动下载静态编译的 PHP 8.5 到 `.local/`（无需 root）。

演示账号（页面右上角切换操作人）：夜审员甲 / 夜审员乙（auditor）、前台小王（frontdesk）、财务小李（finance）。
种子数据已跑完一遍"昨日营业 → 夜审封账 → 今日营业"：昨日已封账（含快照），今日开放且有未核对房单，
另有一笔昨日刷卡的支付回执处于"晚到"状态，可直接演示迟到收款调整。

## 端到端验证

```bash
./scripts/demo.sh   # 需服务已启动；16 项断言覆盖三部分验证
./vendor/bin/phpunit  # 或 .local/bin/php artisan test —— 19 个特性测试
```

`scripts/demo.sh` 验证的三部分（即需求要求的检验）：

1. **营业日归属**：跨午夜入住（23:50 入住，房费次日 00:10 过账，仍归入住当晚）；
   延迟退房费归退房当天；晚到支付回执在原营业日已封账时自动生成"迟到收款"调整单，
   落在当前开放营业日并关联原交易，不修改昨日数字。
2. **并发封账**：两个夜审员同时对同一营业日发封账请求，一个 200 一个 409，全库只有一份快照。
3. **封账后差额核对**：房费更正调整单关联原房费条目；财务报告"快照 + 调整流水 = 有效净额"；
   全局调整单合计与房账调整条目合计对得上；直接改库篡改快照被数据库触发器拒绝。

## 营业日归属规则

| 交易 | 归属营业日 |
| --- | --- |
| 房费 | 住宿夜开始的日期（跨午夜入住归入住当天，与过账时间无关） |
| 加购 / 延迟退房费 / 退款 | 实际发生时所在的开放营业日 |
| 收款 | 发起收款时的开放营业日；回执晚到且原日已封账 → 调整单落在回执到达时的开放营业日 |
| 调整单 | 创建时的开放营业日（永远如此） |

每条房账同时记录 `business_date`（归属营业日）与 `posted_at`（实际过账时间），两者可不同。

## 封账与快照

- 封账前必须核对全部有发生额的房单（`folio_verifications`，按营业日逐本核对），否则 422。
- 封账在单事务内完成：条件 `UPDATE ... WHERE status='open'` 原子占位（并发只成功一方）→
  未核对检查 → 写入三层快照（`night_audit_snapshots` / `snapshot_folios` / `snapshot_items`）→
  自动开启下一营业日。任一步失败整体回滚。
- SQLite 触发器强制：快照三层表禁止 UPDATE/DELETE；已封账营业日的房账条目禁止 INSERT/UPDATE/DELETE；
  调整单只增不改；已封账营业日不得重开。**昨日数字只能读，差额只能走调整单。**

## 调整单与财务核对

- 调整单（`adjustments`）记录：入账营业日、关联房单、**原房账条目**、类型
  （迟到收款/房费更正/加购更正/退款更正）、带符号金额、差额原因、经办人。
- 每笔调整单在房账上生成一条 `type=adjustment` 的条目（落在当前开放营业日），房单余额始终正确。
- 财务视图（`/api/finance/days/{date}`）：快照（冻结）+ 关联到该日的调整单 = 当前有效数字；
  `/api/finance/reconciliation` 校验调整单合计 == 房账调整条目合计。

## 金额约定

整数分存储；房费/加购为正，收款为负，退款为正（冲减已收），房单余额 = 全部已结算条目合计。

## 主要 API

```
GET  /api/audit/current                 夜审页面（当前开放日汇总 + 房单明细 + 核对状态）
POST /api/audit/folios/{id}/verify      核对房单（夜审员）
POST /api/audit/close                   封账 {business_day_id}（夜审员；重复/并发 → 409）
GET  /api/audit/days/{date}             指定营业日夜审数据（已封账含快照）

POST /api/stays                         入住（房态模拟，可指定 check_in_at 模拟跨午夜）
POST /api/stays/{id}/room-charges       过房费 {night_date, rate_cents, posted_at?}
POST /api/stays/{id}/checkout           退房（12:00 后自动计延迟退房费）
POST /api/folios/{id}/addons|refunds    加购 / 退款

POST /api/payments                      收款 {folio_id, amount_cents, method, delay_receipt?}
GET  /api/payments/pending              待到账回执
POST /api/payments/settle-pending       模拟网关回执到达（晚到且原日已封账 → 自动调整单）

GET|POST /api/adjustments               调整单列表 / 新建更正调整单
GET  /api/finance/days[/{date}]         营业日列表 / 封账日核对报告
GET  /api/finance/reconciliation        全局调整流水对账
```

身份：请求头 `X-User-Id`（演示用，前端自动携带当前操作人）。

## 目录速览

```
app/Domain/            业务核心：BusinessDayService（归属规则）、NightAuditService（汇总/封账）、
                       AdjustmentService、MockPaymentGateway、FinanceService
app/Models/            Eloquent 模型（金额一律分）
database/migrations/   领域表 + 不可篡改触发器
resources/js/          React 前端（夜审 / 前台模拟 / 调整单 / 财务核对 四页）
tests/Feature/         19 个特性测试（归属、封账并发、不可篡改、对账）
scripts/demo.sh        端到端验证脚本
start.sh               一条命令启动
```
