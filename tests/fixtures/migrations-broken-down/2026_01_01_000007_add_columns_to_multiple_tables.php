<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('payment_date');
            $table->string('type', 20)->default('settlement');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_date', 'type']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
