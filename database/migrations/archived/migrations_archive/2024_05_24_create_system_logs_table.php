<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Add this line
            $table->string('type');                    // Keep for backward compatibility
            $table->string('log_type');               // Add this for retry history
            $table->string('severity')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('terminal_uid')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
            
            $table->index(['type', 'severity']);
            $table->index('log_type');              // Add index for log_type
            $table->index('terminal_uid');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};