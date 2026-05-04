<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_orders') || ! Schema::hasColumn('portal_orders', 'status')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE portal_orders MODIFY status ENUM('paid','failed','pending_review','approved') NOT NULL DEFAULT 'pending_review'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('portal_orders') || ! Schema::hasColumn('portal_orders', 'status')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('portal_orders')
            ->where('status', 'approved')
            ->update(['status' => 'pending_review']);

        DB::statement("ALTER TABLE portal_orders MODIFY status ENUM('paid','failed','pending_review') NOT NULL DEFAULT 'paid'");
    }
};
