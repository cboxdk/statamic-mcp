<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mcp_oauth_clients', 'registered_ip')) {
            return;
        }

        Schema::table('mcp_oauth_clients', function (Blueprint $table): void {
            $table->string('registered_ip')->nullable()->after('redirect_uris')->index();
        });
    }

    public function down(): void
    {
        // Column may already be absent if the create migration (which includes
        // registered_ip) handled the drop, so guard against double-drop.
        if (! Schema::hasColumn('mcp_oauth_clients', 'registered_ip')) {
            return;
        }

        Schema::table('mcp_oauth_clients', function (Blueprint $table): void {
            $table->dropIndex(['registered_ip']);
            $table->dropColumn('registered_ip');
        });
    }
};
