<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120)->nullable();
            $table->string('author_email')->nullable();
            $table->text('body');
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_spam')->default(false);
            $table->string('submitted_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['feedback_item_id', 'created_at']);
            $table->index('is_spam');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_comments');
    }
};
