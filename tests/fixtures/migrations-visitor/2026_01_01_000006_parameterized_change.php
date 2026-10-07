<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Parameterized ->change() calls — length / precision must be
            // captured so consolidation can reproduce them faithfully.
            $table->string('sku', 32)->change();
            $table->decimal('price', 12, 4)->change();

            // A parameterized added column alongside the changes.
            $table->string('label', 120);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
