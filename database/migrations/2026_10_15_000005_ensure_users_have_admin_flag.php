<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep upgrades from the first VeoPrompter release safe as well as fresh installs.
        if (! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_admin')->default(false);
            });
        }
    }

    public function down(): void
    {
        // Keep the role flag: this is a compatibility guard for databases where the
        // users table may predate the current schema, and dropping it could erase roles.
    }
};
