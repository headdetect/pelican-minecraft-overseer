<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overseer_audit', function (Blueprint $table) {
            // Set when a Discord slash command ran it. user_id is null then, since there's no panel user.
            $table->string('discord_user_id', 20)->nullable()->after('user_id');
            $table->string('discord_name', 64)->nullable()->after('discord_user_id');

            $table->index(['server_id', 'discord_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('overseer_audit', function (Blueprint $table) {
            $table->dropIndex(['server_id', 'discord_user_id']);
            $table->dropColumn(['discord_user_id', 'discord_name']);
        });
    }
};
