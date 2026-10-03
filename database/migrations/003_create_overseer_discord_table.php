<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overseer_discord', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            // One Minecraft server per Discord, so a command finds its server by guild.
            $table->string('guild_id', 20)->nullable()->unique();
            $table->string('guild_name', 100)->nullable();
            $table->string('address')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->text('info')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overseer_discord');
    }
};
