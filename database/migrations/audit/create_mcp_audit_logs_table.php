<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 20);
            $table->string('message');
            $table->string('tool')->nullable()->index();
            $table->string('action')->nullable();
            $table->string('status', 20)->nullable()->index();
            $table->string('correlation_id', 36)->nullable()->index();
            $table->float('duration_ms')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
