<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peachy_ig_story_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('account_id');
            $table->string('provider_post_id', 64);
            $table->string('media_type', 10);
            $table->text('permalink')->nullable();
            $table->dateTime('posted_at');
            $table->string('disk');
            $table->string('image_path')->nullable();
            $table->string('video_path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'provider_post_id']);
            $table->index(['workspace_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peachy_ig_story_media');
    }
};
