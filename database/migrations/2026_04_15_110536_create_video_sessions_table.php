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
        // The live database created this table under the earlier file name
        // 2026_04_15_110537_create_video_sessions_table (renamed to fix the migration
        // ordering). Without this guard that database would try to create it
        // a second time the first time it runs this file.
        if (Schema::hasTable('video_sessions')) {
            return;
        }

        Schema::create('video_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->constrained('users')->cascadeOnDelete();
            $table->string('room_id')->unique();            // generated WebRTC room identifier
            $table->string('status')->default('waiting'); // waiting|active|ended
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_sessions');
    }
};
