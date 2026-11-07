<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('engagement_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
            $table->string('mime', 100)->nullable()->after('file_size');
            $table->string('sha256', 64)->nullable()->after('mime');
            $table->unsignedSmallInteger('page_count')->nullable()->after('sha256');
            $table->unsignedInteger('current_version')->default(1)->after('page_count');
            // Set when a signing envelope is sent: the file can no longer be replaced in place.
            $table->timestamp('locked_at')->nullable()->after('expires_at');

            $table->index('engagement_id');
        });

        Schema::table('contract_versions', function (Blueprint $table) {
            $table->string('kind', 16)->default('revision')->after('version_number'); // original | revision | signed
            $table->string('sha256', 64)->nullable()->after('file_path');
            $table->unsignedBigInteger('size')->nullable()->after('sha256');
            $table->unsignedSmallInteger('page_count')->nullable()->after('size');
            $table->string('mime', 100)->nullable()->after('page_count');
            $table->string('label')->nullable()->after('mime');
            // false when our PDF engine cannot stamp this file (signatures then go in the certificate only).
            $table->boolean('stampable')->default(true)->after('label');

            $table->unique(['contract_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::table('contract_versions', function (Blueprint $table) {
            $table->dropUnique(['contract_id', 'version_number']);
            $table->dropColumn(['kind', 'sha256', 'size', 'page_count', 'mime', 'label', 'stampable']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('engagement_id');
            $table->dropColumn(['mime', 'sha256', 'page_count', 'current_version', 'locked_at']);
        });
    }
};
