<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only create the table if it doesn't already exist
        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
                $table->string('transaction_id')->unique();
                $table->string('hardware_id')->nullable();
                $table->integer('machine_number')->nullable();
                $table->string('store_name')->nullable();
                $table->timestamp('transaction_timestamp');
                $table->decimal('gross_sales', 15, 2);
                $table->decimal('net_sales', 15, 2);
                $table->decimal('vatable_sales', 15, 2);
                $table->decimal('vat_exempt_sales', 15, 2);
                $table->decimal('vat_amount', 15, 2);
                $table->decimal('promo_discount_amount', 15, 2)->nullable();
                $table->string('promo_status')->nullable();
                $table->decimal('discount_total', 15, 2)->nullable();
                $table->json('discount_details')->nullable();
                $table->decimal('other_tax', 15, 2)->nullable();
                $table->decimal('management_service_charge', 15, 2)->nullable();
                $table->decimal('employee_service_charge', 15, 2)->nullable();
                $table->integer('transaction_count');
                $table->string('payload_checksum');
                $table->string('validation_status')->default('pending');
                $table->string('processing_status')->nullable();
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->integer('retry_count')->default(0);
                $table->timestamps();
                
                // Indexes for better query performance
                $table->index('transaction_timestamp');
                $table->index('validation_status');
                $table->index('processing_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop the table in the down method to preserve existing data
        // Only drop it if we're sure it was created by this migration
    }
};