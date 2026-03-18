<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->string('oauth_client_id')->nullable()->after('scopes');
            $table->string('oauth_client_name')->nullable()->after('oauth_client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->dropColumn(['oauth_client_id', 'oauth_client_name']);
        });
    }
};
