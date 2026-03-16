<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (DB::getDriverName() === 'pgsql') {
                $table->rawIndex('status, created_at DESC', 'users_status_created_at_index');
            } else {
                $table->index(['status', 'created_at'], 'users_status_created_at_index');
            }

            $table->index(['email', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_status_created_at_index');
            $table->dropIndex(['email', 'name']);
        });
    }
};
