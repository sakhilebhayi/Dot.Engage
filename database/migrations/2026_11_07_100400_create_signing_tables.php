<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_version_id')->constrained('contract_versions')->cascadeOnDelete();
            $table->string('document_sha256', 64);
            $table->string('mode', 12)->default('sequential'); // sequential | parallel
            // draft | sent | completed | cancelled | expired | declined
            $table->string('status', 12)->default('draft');
            $table->string('signature_level', 12)->default('basic'); // basic: email-verified electronic signature
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('reminder_days')->default(3);
            $table->timestamp('all_signed_at')->nullable();
            $table->timestamp('finalised_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('final_path')->nullable();
            $table->string('final_sha256', 64)->nullable();
            $table->string('certificate_path')->nullable();
            $table->string('certificate_sha256', 64)->nullable();
            $table->boolean('stamped')->default(false);
            $table->longText('evidence')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->text('evidence_seal')->nullable();
            $table->string('seal_public_key', 128)->nullable();
            $table->text('timestamp_token')->nullable();
            $table->string('timestamp_authority')->nullable();
            $table->unsignedTinyInteger('finalise_attempts')->default(0);
            $table->string('finalise_error', 500)->nullable();
            $table->timestamps();

            $table->index(['engagement_id', 'status']);
            $table->index(['contract_id', 'status']);
        });

        Schema::create('signing_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('signing_envelopes')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('engagement_participants')->cascadeOnDelete();
            $table->unsignedSmallInteger('sign_order')->default(1);
            // pending | notified | viewed | signed | declined | reassigned
            $table->string('status', 12)->default('pending');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_text_sha256', 64)->nullable();
            $table->string('method', 12)->nullable(); // drawn | typed | uploaded
            $table->string('typed_name')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('initials_path')->nullable();
            $table->string('identity_method', 24)->nullable(); // email_code | infodot_session
            $table->timestamp('identity_verified_at')->nullable();
            $table->string('signed_email')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('completion_key', 64)->nullable();
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->foreignId('reassigned_to_id')->nullable()->constrained('signing_signers')->nullOnDelete();
            $table->timestamps();

            $table->index(['envelope_id', 'status']);
            $table->index('participant_id');
        });

        Schema::create('signing_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('signing_envelopes')->cascadeOnDelete();
            $table->foreignId('signer_id')->constrained('signing_signers')->cascadeOnDelete();
            $table->string('type', 12); // signature | initials | date | text | checkbox
            $table->unsignedSmallInteger('page');
            $table->decimal('x', 7, 6);
            $table->decimal('y', 7, 6);
            $table->decimal('w', 7, 6);
            $table->decimal('h', 7, 6);
            $table->boolean('required')->default(true);
            $table->string('label')->nullable();
            $table->text('value')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'page']);
            $table->index('signer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_fields');
        Schema::dropIfExists('signing_signers');
        Schema::dropIfExists('signing_envelopes');
    }
};
