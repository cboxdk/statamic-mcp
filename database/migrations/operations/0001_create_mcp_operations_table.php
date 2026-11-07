<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_operations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('version');
            $table->longText('payload');
            $table->string('state', 32);
            $table->unsignedBigInteger('expires_at')->index();
        });
        Schema::create('mcp_operation_capacity', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('record_count');
        });
        DB::table('mcp_operation_capacity')->insert(['id' => 1, 'record_count' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_operations');
        Schema::dropIfExists('mcp_operation_capacity');
    }
};
