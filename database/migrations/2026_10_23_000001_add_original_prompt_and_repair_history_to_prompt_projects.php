<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_projects', function (Blueprint $table) {
            $table->longText('original_content')->nullable();
            $table->json('repair_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prompt_projects', function (Blueprint $table) {
            $table->dropColumn(['original_content', 'repair_history']);
        });
    }
};
