<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100);
            $table->string('locale', 10)->default('en');
            $table->string('text');
            $table->unique(['code', 'locale']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_messages');
    }
};
