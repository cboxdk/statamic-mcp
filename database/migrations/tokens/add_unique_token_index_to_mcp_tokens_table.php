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
            // Drop the existing non-unique index and add a unique constraint
            $table->dropIndex(['token']);
            $table->unique('token');

            // Add index for efficient expired token pruning
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->dropUnique(['token']);
            $table->index('token');

            $table->dropIndex(['expires_at']);
        });
    }
};
