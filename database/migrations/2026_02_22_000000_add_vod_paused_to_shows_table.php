<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            // Tracks whether VOD recording has been paused by an admin (e.g. during intermissions).
            // When true, stream-manager.sh stops archive FFmpeg without ending the show.
            // When false again, stream-manager.sh resumes archive FFmpeg from where it left off.
            $table->boolean('vod_paused')->default(false)->after('recordable');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('vod_paused');
        });
    }
};
