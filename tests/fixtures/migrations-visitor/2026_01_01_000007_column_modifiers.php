<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('nickname')->nullable();
            $table->integer('credits')->default(100);
            $table->unsignedBigInteger('owner_id');
            $table->boolean('active')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['nickname', 'credits', 'owner_id']);
        });
    }
};
