<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');

            // Transaction Identity
            $table->uuid('transaction_id')->unique();
            $table->string('store_name')->nullable();
            $table->string('hardware_id');
            $table->integer('machine_number');
            $table->dateTime('transaction_timestamp');

            // Sales & Financials
            $table->decimal('gross_sales', 15, 2);
            $table->decimal('net_sales', 15, 2)->nullable();
            $table->decimal('vatable_sales', 15, 2)->nullable();
            $table->decimal('vat_exempt_sales', 15, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->decimal('promo_discount_amount', 15, 2)->nullable();
            $table->enum('promo_status', ['WITH_APPROVAL', 'WITHOUT_APPROVAL'])->nullable();
            $table->decimal('discount_total', 15, 2)->nullable();
            $table->json('discount_details')->nullable();
            $table->decimal('other_tax', 15, 2)->nullable();
            $table->decimal('management_service_charge', 15, 2)->nullable();
            $table->decimal('employee_service_charge', 15, 2)->nullable();

            // Metadata & Validation
            $table->integer('transaction_count')->default(1);
            $table->string('payload_checksum', 64);
            $table->enum('validation_status', ['VALID', 'ERROR', 'PENDING'])->default('PENDING');
            $table->string('error_code')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('transactions');
    }
};

