<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'pending_review' to the provisioning_status enum on order_items
        DB::statement("ALTER TABLE `order_items` MODIFY `provisioning_status` ENUM('active','expired','unpaid','undergoing_provisioning','pending_review') NOT NULL DEFAULT 'undergoing_provisioning'");
    }

    public function down(): void
    {
        // Revert to previous enum values (remove 'pending_review')
        DB::statement("ALTER TABLE `order_items` MODIFY `provisioning_status` ENUM('active','expired','unpaid','undergoing_provisioning') NOT NULL DEFAULT 'undergoing_provisioning'");
    }
};
