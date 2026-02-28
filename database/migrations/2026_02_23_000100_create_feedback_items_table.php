<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_items', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description');
            $table->enum('type', ['idea', 'bug', 'improvement'])->default('idea');
            $table->enum('status', ['submitted', 'reported', 'planned', 'in_progress', 'shipped', 'closed'])->default('submitted');
            $table->unsignedInteger('vote_count')->default(0);
            $table->string('contact_email')->nullable();
            $table->text('browser_notes')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['vote_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_items');
    }
};

