<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'owner_id')) {
                $table->foreignId('owner_id')->nullable()->after('registration_reviewed_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'client_source')) {
                $table->enum('client_source', ['registered', 'created'])->default('registered')->after('owner_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'owner_id')) {
                $table->dropConstrainedForeignId('owner_id');
            }

            if (Schema::hasColumn('users', 'client_source')) {
                $table->dropColumn('client_source');
            }
        });
    }
};
