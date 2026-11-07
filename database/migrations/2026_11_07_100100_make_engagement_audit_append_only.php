<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The audit trail is evidence. The database itself refuses to change or remove a row once written
     * (the application also hash-chains the rows, so a change made behind the database's back shows up).
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER engagement_audit_no_update BEFORE UPDATE ON engagement_audit_events BEGIN SELECT RAISE(ABORT, 'engagement audit events are append-only'); END");
            DB::unprepared("CREATE TRIGGER engagement_audit_no_delete BEFORE DELETE ON engagement_audit_events BEGIN SELECT RAISE(ABORT, 'engagement audit events are append-only'); END");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER engagement_audit_no_update BEFORE UPDATE ON engagement_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'engagement audit events are append-only'");
            DB::unprepared("CREATE TRIGGER engagement_audit_no_delete BEFORE DELETE ON engagement_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'engagement audit events are append-only'");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS engagement_audit_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS engagement_audit_no_delete');
    }
};
