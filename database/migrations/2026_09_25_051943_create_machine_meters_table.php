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
        Schema::create('machine_meters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coin_in_cents')->default(0);
            $table->unsignedBigInteger('coin_out_cents')->default(0);
            $table->unsignedBigInteger('games_played')->default(0);
            $table->unsignedBigInteger('free_games_triggered')->default(0);
            $table->unsignedBigInteger('bale_bonus_triggered')->default(0);
            $table->unsignedBigInteger('major_hits')->default(0);
            $table->unsignedBigInteger('grand_hits')->default(0);
            $table->unsignedBigInteger('progressives_paid_cents')->default(0);
            $table->unsignedBigInteger('deposits_cents')->default(0);
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_meters');
    }
};
