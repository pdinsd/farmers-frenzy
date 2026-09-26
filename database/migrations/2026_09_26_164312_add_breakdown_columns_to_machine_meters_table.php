<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('machine_meters', function (Blueprint $table) {
            $table->unsignedBigInteger('winning_games')->default(0)->after('games_played');
            $table->unsignedBigInteger('line_wins_cents')->default(0)->after('coin_out_cents');
            $table->unsignedBigInteger('scatter_wins_cents')->default(0)->after('line_wins_cents');
            $table->unsignedBigInteger('free_games_wins_cents')->default(0)->after('scatter_wins_cents');
            $table->unsignedBigInteger('bale_bonus_wins_cents')->default(0)->after('free_games_wins_cents');
            $table->unsignedBigInteger('mini_hits')->default(0)->after('bale_bonus_triggered');
            $table->unsignedBigInteger('minor_hits')->default(0)->after('mini_hits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machine_meters', function (Blueprint $table) {
            $table->dropColumn([
                'winning_games', 'line_wins_cents', 'scatter_wins_cents', 'free_games_wins_cents',
                'bale_bonus_wins_cents', 'mini_hits', 'minor_hits',
            ]);
        });
    }
};
