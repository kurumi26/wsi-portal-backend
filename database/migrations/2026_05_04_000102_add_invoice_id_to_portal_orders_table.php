<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_orders') || ! Schema::hasTable('invoices')) {
            return;
        }

        if (! Schema::hasColumn('portal_orders', 'invoice_id')) {
            Schema::table('portal_orders', function (Blueprint $table) {
                $table->foreignId('invoice_id')->nullable()->after('id')->constrained('invoices')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portal_orders', 'invoice_id')) {
            Schema::table('portal_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('invoice_id');
            });
        }
    }
};
