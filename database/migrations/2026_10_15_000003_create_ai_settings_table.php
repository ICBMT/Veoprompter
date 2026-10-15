<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('gemini');
            $table->string('model')->default('gemini-2.5-flash');
            $table->string('base_url')->nullable();
            // Laravel's encrypted cast adds ciphertext overhead, so credentials need a long column.
            $table->longText('api_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
