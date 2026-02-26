<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insert([
            ['key' => 'site_name', 'value' => 'My App'],
            ['key' => 'theme', 'value' => 'default'],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'site_name', 'theme',
        ])->delete();
    }
};
