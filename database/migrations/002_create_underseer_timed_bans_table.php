<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('underseer_timed_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('player', 32);
            $table->string('reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index(['lifted_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('underseer_timed_bans');
    }
};
