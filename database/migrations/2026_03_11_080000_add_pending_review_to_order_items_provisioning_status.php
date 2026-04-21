<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // Add 'pending_review' to the provisioning_status enum on order_items
        DB::statement("ALTER TABLE `order_items` MODIFY `provisioning_status` ENUM('active','expired','unpaid','undergoing_provisioning','pending_review') NOT NULL DEFAULT 'undergoing_provisioning'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // Revert to previous enum values (remove 'pending_review')
        DB::statement("ALTER TABLE `order_items` MODIFY `provisioning_status` ENUM('active','expired','unpaid','undergoing_provisioning') NOT NULL DEFAULT 'undergoing_provisioning'");
    }
};
