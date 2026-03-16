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
        Schema::table('orders', function (Blueprint $table) {
            if (DB::getDriverName() === 'pgsql') {
                $table->rawIndex('status, created_at DESC', 'orders_status_created_at_index');
            } else {
                $table->index(['status', 'created_at'], 'orders_status_created_at_index');
            }
            $table->index('paymentstatus');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index('sale_status');
            $table->index('availability');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index('accountmanager_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_at_index');
            $table->dropIndex(['paymentstatus']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sale_status']);
            $table->dropIndex(['availability']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['accountmanager_id']);
        });
    }
};
