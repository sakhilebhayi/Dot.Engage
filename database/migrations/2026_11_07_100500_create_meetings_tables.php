<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('kind', 10)->default('scheduled'); // instant | scheduled
            // scheduled | live | ended | cancelled
            $table->string('status', 12)->default('scheduled');
            $table->string('provider', 10)->default('native'); // native | daily
            $table->string('room_key', 40)->unique();
            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->boolean('waiting_room')->default(true);
            $table->boolean('locked')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 24)->nullable(); // host_ended | empty | expired | cancelled
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['engagement_id', 'status']);
            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('engagement_participants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('is_host')->default(false);
            // invited | waiting | admitted | joined | left | removed | denied
            $table->string('state', 10)->default('invited');
            $table->string('client_id', 40)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('mic_on')->default(false);
            $table->boolean('cam_on')->default(false);
            $table->boolean('sharing')->default(false);
            $table->string('quality', 8)->nullable(); // good | fair | poor
            $table->unsignedSmallInteger('join_count')->default(0);
            $table->timestamps();

            $table->index(['meeting_id', 'state']);
        });

        Schema::create('meeting_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_attendee_id')->constrained('meeting_attendees')->cascadeOnDelete();
            $table->foreignId('to_attendee_id')->nullable()->constrained('meeting_attendees')->cascadeOnDelete();
            $table->string('type', 16); // offer | answer | candidate | command | state
            $table->text('payload');
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['meeting_id', 'id']);
            $table->index(['to_attendee_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_signals');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
    }
};
