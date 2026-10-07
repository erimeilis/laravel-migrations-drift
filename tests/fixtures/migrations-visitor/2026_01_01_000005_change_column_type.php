<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Type-only change: widen an existing column.
            $table->text('source_document_id')->change();

            // Change with chained modifiers before ->change().
            $table->boolean('is_active')->nullable()->change();

            // A genuine new column (not a change) in the same migration.
            $table->string('new_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('new_note');
        });
    }
};
