<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 128)->index();
            $table->string('client_id')->index();
            $table->string('user_id');
            $table->json('scopes');
            $table->string('code_challenge');
            $table->string('code_challenge_method', 10);
            $table->text('redirect_uri');
            $table->timestamp('expires_at')->index();
            $table->boolean('used')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_codes');
    }
};
