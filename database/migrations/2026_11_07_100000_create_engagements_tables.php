<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('title');
            $table->string('kind', 40)->default('general');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 40)->default('draft');
            $table->timestamp('state_changed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('expired_at')->nullable();
            // The version of the document the parties agreed (every approver approved it, or the owner
            // recorded agreement when nobody needs to approve). A new version clears it.
            $table->unsignedBigInteger('agreed_version_id')->nullable();
            $table->timestamp('agreed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'state']);
        });

        Schema::create('engagement_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_host')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('engagement_id');
        });

        Schema::create('engagement_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('engagement_parties')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            // owner | admin | manager | reviewer | participant | external
            $table->string('role', 24)->default('external');
            // approve | sign | host
            $table->json('capabilities')->nullable();
            $table->boolean('required')->default(true);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Access to the engagement for people without an account: a random token (only its
            // hash is stored) plus an emailed one-time code.
            $table->string('access_token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('otp_hash', 64)->nullable();
            $table->timestamp('otp_sent_at')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['engagement_id', 'email']);
            $table->index('user_id');
        });

        Schema::create('engagement_audit_events', function (Blueprint $table) {
            $table->id();
            // No foreign key on purpose: the evidence has to outlive the rows it describes.
            $table->unsignedBigInteger('engagement_id')->index();
            $table->string('event', 64);
            $table->string('actor_type', 16);   // user | participant | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label');
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('occurred_at', 6);
            $table->string('prev_hash', 64);
            $table->string('hash', 64);

            $table->index(['engagement_id', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('engagement_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('engagement_participants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['engagement_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_messages');
        Schema::dropIfExists('engagement_audit_events');
        Schema::dropIfExists('engagement_participants');
        Schema::dropIfExists('engagement_parties');
        Schema::dropIfExists('engagements');
    }
};
