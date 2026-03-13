<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_order_id')->constrained('portal_orders')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method');
            $table->enum('status', ['success', 'failed', 'pending'])->default('success');
            $table->string('transaction_ref')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
