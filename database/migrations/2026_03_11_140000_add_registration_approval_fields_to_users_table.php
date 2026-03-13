<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('registration_status', ['pending', 'approved', 'rejected'])->default('approved')->after('is_enabled');
            $table->text('registration_admin_notes')->nullable()->after('registration_status');
            $table->foreignId('registration_reviewed_by')->nullable()->after('registration_admin_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('registration_reviewed_at')->nullable()->after('registration_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registration_reviewed_by');
            $table->dropColumn([
                'registration_status',
                'registration_admin_notes',
                'registration_reviewed_at',
            ]);
        });
    }
};
