<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mixpost_youtube_audience_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('account_id');
            $table->string('channel_id', 64);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('subscription', 16)->default('all');
            $table->json('report_json');
            $table->dateTime('fetched_at', 6);
            $table->dateTime('last_attempt_at', 6);
            $table->string('last_attempt_status', 32);
            $table->string('last_attempt_error', 64)->nullable();
            $table->unique(['workspace_id', 'account_id', 'start_date', 'end_date', 'subscription'], 'youtube_audience_period_unique');
            $table->foreign('workspace_id', 'youtube_audience_workspace_fk')->references('id')->on('mixpost_workspaces')->cascadeOnDelete();
            $table->foreign('account_id', 'youtube_audience_account_fk')->references('id')->on('mixpost_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mixpost_youtube_audience_reports');
    }
};
