<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('post_id')->references('id')->on('posts');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // BUG: missing dropForeign — just drops the column
            $table->dropColumn('post_id');
        });
    }
};
