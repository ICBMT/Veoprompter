<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_projects', function (Blueprint $table) {
            $table->foreignId('parent_prompt_id')->nullable()->constrained('prompt_projects')->nullOnDelete();
            $table->unsignedInteger('story_part')->default(1);
            $table->unsignedInteger('story_batch')->default(1);
            $table->string('story_status', 12)->default('ended');
            $table->json('story_context')->nullable();
            $table->string('continuation_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('prompt_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_prompt_id');
            $table->dropUnique(['continuation_key']);
            $table->dropColumn(['story_part', 'story_batch', 'story_status', 'story_context', 'continuation_key']);
        });
    }
};
