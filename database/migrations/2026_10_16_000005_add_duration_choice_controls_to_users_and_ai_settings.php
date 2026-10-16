<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('allow_clip_duration_choice')->default(true);
            $table->boolean('allow_extension_duration_choice')->default(true);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_choose_clip_duration')->default(true);
            $table->boolean('can_choose_extension_duration')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_choose_clip_duration', 'can_choose_extension_duration']);
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_clip_duration_choice', 'allow_extension_duration_choice']);
        });
    }
};
