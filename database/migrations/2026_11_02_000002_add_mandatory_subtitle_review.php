<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_videos', function (Blueprint $table) {
            $table->json('transcript_source')->nullable();
            $table->json('transcript_draft')->nullable();
            $table->json('transcript_approved')->nullable();
            $table->unsignedInteger('transcript_revision')->default(0);
            $table->unsignedInteger('approved_revision')->nullable();
            $table->string('approved_hash', 64)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('review_ready_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['transcript_source', 'transcript_draft', 'transcript_approved', 'transcript_revision', 'approved_revision', 'approved_hash', 'approved_at', 'review_ready_at']);
        });
    }
};
