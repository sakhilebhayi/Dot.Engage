<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependency_patch_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('manager');
            $table->json('advisories');
            $table->text('risk_summary');
            $table->string('proposed_command');
            $table->string('status')->default('pending_approval');
            $table->text('rejected_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('applied_log')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependency_patch_proposals');
    }
};
