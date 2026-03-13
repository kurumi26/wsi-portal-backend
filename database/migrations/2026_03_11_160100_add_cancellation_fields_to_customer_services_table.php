<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_services', function (Blueprint $table) {
            $table->enum('cancellation_status', ['pending', 'approved', 'rejected'])->nullable()->after('status');
            $table->text('cancellation_reason')->nullable()->after('cancellation_status');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancellation_reason');
            $table->foreignId('cancellation_reviewed_by')->nullable()->after('cancellation_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancellation_reviewed_at')->nullable()->after('cancellation_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('customer_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancellation_reviewed_by');
            $table->dropColumn([
                'cancellation_status',
                'cancellation_reason',
                'cancellation_requested_at',
                'cancellation_reviewed_at',
            ]);
        });
    }
};
