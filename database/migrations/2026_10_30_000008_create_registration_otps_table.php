<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_otps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->string('email')->nullable()->index();
            $table->string('phone', 16)->nullable()->index();
            $table->string('channel', 10);
            $table->string('password_hash');
            $table->string('code_hash');
            $table->timestamp('expires_at')->useCurrent()->index();
            $table->timestamp('last_sent_at')->useCurrent();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_otps');
    }
};
