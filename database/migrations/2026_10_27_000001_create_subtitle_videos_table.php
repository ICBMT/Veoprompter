<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_videos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('upload_token');
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            $table->string('status')->default('uploaded')->index();
            $table->json('settings');
            $table->json('media')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'upload_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_videos');
    }
};
