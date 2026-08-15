<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Hyrox surface became Race. Stored cards move to the canonical value
 * so the read side never has to know the old one; the MCP tools keep
 * accepting "hyrox" and store it as "race" (DashboardCard::LEGACY_SECTIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dashboard_cards')->where('section', 'hyrox')->update(['section' => 'race']);
    }

    public function down(): void
    {
        DB::table('dashboard_cards')->where('section', 'race')->update(['section' => 'hyrox']);
    }
};
