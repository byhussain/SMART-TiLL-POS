<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds header and footer note columns and backfills header note from note on sqlite', function (): void {
    $databasePath = database_path('testing-header-footer-notes.sqlite');

    if (file_exists($databasePath)) {
        unlink($databasePath);
    }

    touch($databasePath);

    config()->set('database.connections.sqlite.database', $databasePath);
    config()->set('database.default', 'sqlite');

    DB::purge('sqlite');

    Schema::create('sales', function (Blueprint $table): void {
        $table->id();
        $table->text('note')->nullable();
    });

    DB::table('sales')->insert([
        'id' => 1,
        'note' => 'Existing header note',
    ]);

    $migration = require base_path('vendor/smart-till/core/database/migrations/2026_03_25_150000_add_header_and_footer_notes_to_sales_table.php');
    $migration->up();

    expect(Schema::hasColumns('sales', ['header_note', 'footer_note']))->toBeTrue();

    $sale = DB::table('sales')->where('id', 1)->first();

    expect($sale->header_note)->toBe('Existing header note')
        ->and($sale->footer_note)->toBeNull();

    DB::disconnect('sqlite');
    unlink($databasePath);
});
