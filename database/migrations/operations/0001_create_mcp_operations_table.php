<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_operations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('version');
            $table->longText('payload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_operations');
    }
};
