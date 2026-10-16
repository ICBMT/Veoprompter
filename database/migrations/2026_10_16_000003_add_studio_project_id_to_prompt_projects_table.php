<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_projects', function (Blueprint $table) {
            $table->foreignId('studio_project_id')
                ->nullable()
                ->after('user_id')
                ->constrained('studio_projects')
                ->nullOnDelete();
            $table->index(['studio_project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('prompt_projects', function (Blueprint $table) {
            $table->dropForeign(['studio_project_id']);
            $table->dropIndex(['studio_project_id', 'created_at']);
            $table->dropColumn('studio_project_id');
        });
    }
};
