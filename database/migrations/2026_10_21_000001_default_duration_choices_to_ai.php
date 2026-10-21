<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('allow_clip_duration_choice')->default(false)->change();
            $table->boolean('allow_extension_duration_choice')->default(false)->change();
        });

        DB::table('ai_settings')->update([
            'allow_clip_duration_choice' => false,
            'allow_extension_duration_choice' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('allow_clip_duration_choice')->default(true)->change();
            $table->boolean('allow_extension_duration_choice')->default(true)->change();
        });
    }
};
