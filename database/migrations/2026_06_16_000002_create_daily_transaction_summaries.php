<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('daily_transaction_summaries')) {
            Schema::create('daily_transaction_summaries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('terminal_id')->constrained('pos_terminals')->cascadeOnDelete();
                $table->date('business_date');
                $table->unsignedInteger('transaction_count')->default(0);
                $table->unsignedInteger('unique_receipts')->default(0);
                $table->decimal('gross_sales', 15, 2)->default(0);
                $table->decimal('net_sales', 15, 2)->default(0);
                $table->decimal('vatable_sales', 15, 2)->default(0);
                $table->decimal('vat_amount', 15, 2)->default(0);
                $table->decimal('sc_vat_exempt_sales', 15, 2)->default(0);
                $table->decimal('refund_amount', 15, 2)->default(0);
                $table->decimal('promo_with_approval', 15, 2)->default(0);
                $table->decimal('promo_without_approval', 15, 2)->default(0);
                $table->decimal('employee_discount', 15, 2)->default(0);
                $table->decimal('senior_discount', 15, 2)->default(0);
                $table->decimal('pwd_discount', 15, 2)->default(0);
                $table->decimal('vip_discount', 15, 2)->default(0);
                $table->decimal('regular_discount', 15, 2)->default(0);
                $table->decimal('other_tax', 15, 2)->default(0);
                $table->decimal('service_charge_distributed', 15, 2)->default(0);
                $table->decimal('service_charge_retained', 15, 2)->default(0);
                $table->timestamp('refreshed_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'terminal_id', 'business_date'], 'uq_daily_tx_summary_tenant_terminal_date');
                $table->index(['tenant_id', 'business_date'], 'idx_daily_tx_summary_tenant_date');
                $table->index(['business_date', 'tenant_id'], 'idx_daily_tx_summary_date_tenant');
            });
        }

        if (! Schema::hasTable('report_refresh_states')) {
            Schema::create('report_refresh_states', function (Blueprint $table) {
                $table->id();
                $table->string('report_type', 80);
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->date('business_date');
                $table->string('status', 30)->default('completed');
                $table->timestamp('refreshed_at')->nullable();
                $table->timestamps();

                $table->unique(['report_type', 'tenant_id', 'business_date'], 'uq_report_refresh_state');
                $table->index(['report_type', 'business_date', 'status'], 'idx_report_refresh_state_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_refresh_states');
        Schema::dropIfExists('daily_transaction_summaries');
    }
};
