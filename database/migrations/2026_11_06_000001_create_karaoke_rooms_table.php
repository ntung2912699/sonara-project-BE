<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karaoke_rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('joined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('beat_type', 16);
            $table->string('beat_id', 80);
            $table->string('beat_title');
            $table->string('beat_artist')->nullable();
            $table->string('status', 16)->default('waiting');
            $table->timestamp('starts_at')->nullable();
            $table->json('webrtc_offer')->nullable();
            $table->json('webrtc_answer')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karaoke_rooms');
    }
};
