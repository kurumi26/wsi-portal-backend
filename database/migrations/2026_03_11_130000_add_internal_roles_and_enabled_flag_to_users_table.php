<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('role');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('customer', 'admin', 'technical_support', 'sales') NOT NULL DEFAULT 'customer'");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET role = 'admin' WHERE role IN ('technical_support', 'sales')");

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('customer', 'admin') NOT NULL DEFAULT 'customer'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }
};
