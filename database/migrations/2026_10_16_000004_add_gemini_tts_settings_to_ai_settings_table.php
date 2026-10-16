<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('tts_model')->default('gemini-2.5-flash-preview-tts');
            // Provider credentials are encrypted by the model cast; keep room for ciphertext overhead.
            $table->longText('tts_api_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['tts_model', 'tts_api_key']);
        });
    }
};
