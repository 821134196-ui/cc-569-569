<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 数据库级防篡改保证：
 *  1. 夜审快照（头/房单/条目三层）一旦写入，禁止 UPDATE / DELETE。
 *  2. 已封账营业日的房账条目禁止 INSERT / UPDATE / DELETE ——
 *     迟到收款、房费更正只能以调整单形式落在当前开放营业日，
 *     不能靠修改昨日数字消除差额。
 *  3. 调整单本身只增不改（append-only）。
 *  4. 营业日一旦 closed 不得改回其他状态，且不得删除。
 */
return new class extends Migration
{
    public function up(): void
    {
        $snapshotTables = ['night_audit_snapshots', 'snapshot_folios', 'snapshot_items'];
        foreach ($snapshotTables as $table) {
            DB::unprepared("
                CREATE TRIGGER trg_{$table}_no_update
                BEFORE UPDATE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, 'night audit snapshots are immutable');
                END;
            ");
            DB::unprepared("
                CREATE TRIGGER trg_{$table}_no_delete
                BEFORE DELETE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, 'night audit snapshots are immutable');
                END;
            ");
        }

        DB::unprepared("
            CREATE TRIGGER trg_folio_items_no_insert_closed_day
            BEFORE INSERT ON folio_items
            WHEN EXISTS (SELECT 1 FROM business_days WHERE date = NEW.business_date AND status = 'closed')
            BEGIN
                SELECT RAISE(ABORT, 'business day is closed: use an adjustment instead');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_folio_items_no_update_closed_day
            BEFORE UPDATE ON folio_items
            WHEN EXISTS (SELECT 1 FROM business_days WHERE date = OLD.business_date AND status = 'closed')
                OR EXISTS (SELECT 1 FROM business_days WHERE date = NEW.business_date AND status = 'closed')
            BEGIN
                SELECT RAISE(ABORT, 'business day is closed: use an adjustment instead');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_folio_items_no_delete_closed_day
            BEFORE DELETE ON folio_items
            WHEN EXISTS (SELECT 1 FROM business_days WHERE date = OLD.business_date AND status = 'closed')
            BEGIN
                SELECT RAISE(ABORT, 'business day is closed: use an adjustment instead');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_adjustments_no_update
            BEFORE UPDATE ON adjustments
            BEGIN
                SELECT RAISE(ABORT, 'adjustments are append-only');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_adjustments_no_delete
            BEFORE DELETE ON adjustments
            BEGIN
                SELECT RAISE(ABORT, 'adjustments are append-only');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_business_days_no_reopen
            BEFORE UPDATE ON business_days
            WHEN OLD.status = 'closed' AND NEW.status != 'closed'
            BEGIN
                SELECT RAISE(ABORT, 'a closed business day cannot be reopened');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_business_days_no_delete
            BEFORE DELETE ON business_days
            BEGIN
                SELECT RAISE(ABORT, 'business days cannot be deleted');
            END;
        ");
    }

    public function down(): void
    {
        $triggers = [
            'trg_night_audit_snapshots_no_update', 'trg_night_audit_snapshots_no_delete',
            'trg_snapshot_folios_no_update', 'trg_snapshot_folios_no_delete',
            'trg_snapshot_items_no_update', 'trg_snapshot_items_no_delete',
            'trg_folio_items_no_insert_closed_day', 'trg_folio_items_no_update_closed_day',
            'trg_folio_items_no_delete_closed_day',
            'trg_adjustments_no_update', 'trg_adjustments_no_delete',
            'trg_business_days_no_reopen', 'trg_business_days_no_delete',
        ];
        foreach ($triggers as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
