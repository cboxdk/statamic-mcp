<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['mcp_oauth_codes', 'mcp_oauth_refresh_tokens'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('capabilities')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['mcp_oauth_codes', 'mcp_oauth_refresh_tokens'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('capabilities');
            });
        }
    }
};
