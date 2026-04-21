<?php

use App\Models\Contract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('signed_document_uploaded_by')
                ->nullable()
                ->after('signed_document_uploaded_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('verification_status')->nullable()->after('verified_at');
        });

        DB::table('contracts')
            ->whereNotNull('verified_at')
            ->update([
                'verification_status' => Contract::VERIFICATION_VERIFIED,
            ]);

        DB::table('contracts')
            ->whereNull('verification_status')
            ->update([
                'verification_status' => Contract::VERIFICATION_PENDING,
            ]);
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_document_uploaded_by');
            $table->dropColumn('verification_status');
        });
    }
};
