<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('artist');
            $table->string('album')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('cover_image_url')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('source')->default('catalog');
            $table->string('license_name')->nullable();
            $table->string('license_url')->nullable();
            $table->boolean('downloadable')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['title', 'artist']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
