<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('portal_orders')->nullOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained('customer_services')->nullOnDelete();
            $table->string('external_key')->unique();
            $table->enum('scope', ['checkout', 'order', 'service'])->default('order');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('service_name')->nullable();
            $table->string('version')->nullable();
            $table->enum('status', ['Pending Review', 'Accepted', 'Rejected'])->default('Pending Review');
            $table->boolean('agreement_accepted')->default(false);
            $table->boolean('terms_accepted')->default(false);
            $table->boolean('privacy_accepted')->default(false);
            $table->boolean('requires_signed_document')->default(false);
            $table->string('signed_document_name')->nullable();
            $table->string('signed_document_path')->nullable();
            $table->timestamp('signed_document_uploaded_at')->nullable();
            $table->string('download_path')->nullable();
            $table->string('audit_reference')->nullable();
            $table->string('decision_by')->nullable();
            $table->timestamp('decision_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('document_sections')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('order_id');
            $table->index('customer_service_id');
            $table->index(['scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
