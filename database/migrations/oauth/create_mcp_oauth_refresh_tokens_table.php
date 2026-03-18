<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 128)->index();
            $table->string('client_id')->index();
            $table->string('user_id');
            $table->json('scopes');
            $table->timestamp('expires_at')->index();
            $table->boolean('used')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_refresh_tokens');
    }
};
