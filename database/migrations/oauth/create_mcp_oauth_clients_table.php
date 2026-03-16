<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table): void {
            $table->string('client_id')->primary();
            $table->string('client_name');
            $table->json('redirect_uris');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
