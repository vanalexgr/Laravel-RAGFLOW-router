<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_state_events', function (Blueprint $table): void {
            $table->id();
            $table->char('conversation_key', 64);
            $table->unsignedBigInteger('sequence');
            $table->json('payload');
            $table->timestamp('created_at');
            $table->unique(['conversation_key', 'sequence']);
            $table->index('conversation_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_state_events');
    }
};
