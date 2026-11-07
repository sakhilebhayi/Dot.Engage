<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_version_id')->nullable()->constrained('contract_versions')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('document_comments')->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('engagement_participants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->string('kind', 12)->default('comment'); // comment | question
            $table->text('body');
            $table->unsignedSmallInteger('page')->nullable();
            $table->text('quote')->nullable();
            // Where on the page: fractions of the page, origin top-left.
            $table->decimal('x', 7, 6)->nullable();
            $table->decimal('y', 7, 6)->nullable();
            $table->decimal('w', 7, 6)->nullable();
            $table->decimal('h', 7, 6)->nullable();
            $table->string('status', 12)->default('open'); // open | discussed | resolved
            $table->foreignId('resolved_by')->nullable()->constrained('engagement_participants')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('discussed_at')->nullable();
            $table->json('mentions')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'contract_version_id']);
            $table->index(['engagement_id', 'status']);
        });

        Schema::create('amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('base_version_id')->nullable()->constrained('contract_versions')->nullOnDelete();
            $table->foreignId('applied_in_version_id')->nullable()->constrained('contract_versions')->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('engagement_participants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->string('type', 12); // replace | add | remove
            $table->string('clause_ref')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->text('original_text')->nullable();
            $table->text('proposed_text')->nullable();
            $table->text('explanation')->nullable();
            // proposed | accepted | rejected | withdrawn
            $table->string('status', 12)->default('proposed');
            $table->foreignId('reviewed_by')->nullable()->constrained('engagement_participants')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
            $table->index(['engagement_id', 'status']);
        });

        Schema::create('engagement_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_version_id')->constrained('contract_versions')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('engagement_participants')->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected
            $table->string('note', 500)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['contract_version_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_approvals');
        Schema::dropIfExists('amendments');
        Schema::dropIfExists('document_comments');
    }
};
