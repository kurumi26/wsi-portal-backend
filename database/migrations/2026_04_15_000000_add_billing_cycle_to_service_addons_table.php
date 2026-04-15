<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_addons', function (Blueprint $table) {
            $table->string('billing_cycle')->nullable()->after('extra_price');
        });

        DB::table('service_addons')
            ->join('services', 'services.id', '=', 'service_addons.service_id')
            ->select('service_addons.id as addon_id', 'services.billing_cycle')
            ->orderBy('service_addons.id')
            ->chunkById(100, function ($addons): void {
                foreach ($addons as $addon) {
                    DB::table('service_addons')
                        ->where('id', $addon->addon_id)
                        ->update(['billing_cycle' => $addon->billing_cycle]);
                }
            }, 'service_addons.id', 'addon_id');
    }

    public function down(): void
    {
        Schema::table('service_addons', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
