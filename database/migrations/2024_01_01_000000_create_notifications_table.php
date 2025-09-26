<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('info'); // info, warning, error, success, urgent
            $table->json('data')->nullable(); // Additional data for the notification
            $table->string('channel')->default('database'); // database, email, push, all
            $table->boolean('urgent')->default(false);
            $table->timestamp('scheduled_at')->nullable(); // For scheduled notifications
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->boolean('email_sent')->default(false);
            $table->boolean('push_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'user_id']);

            // Add indexes for better performance
            $table->index(['user_id', 'read']);
            $table->index(['notification_id', 'read']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type'); // info, warning, error, success, urgent
            $table->boolean('database_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('endpoint'); // WebPush endpoint URL
            $table->string('p256dh_key'); // Public key
            $table->string('auth_token'); // Auth token
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Add indexes for better performance
            $table->index(['user_id', 'active']);
            $table->index('endpoint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_user');
        Schema::dropIfExists('notifications');
    }
};
