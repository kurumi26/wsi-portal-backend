<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('portal_orders', 'customer_note')) {
            return;
        }

        Schema::table('portal_orders', function (Blueprint $table) {
            $table->text('customer_note')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('portal_orders', 'customer_note')) {
            return;
        }

        Schema::table('portal_orders', function (Blueprint $table) {
            $table->dropColumn('customer_note');
        });
    }
};
