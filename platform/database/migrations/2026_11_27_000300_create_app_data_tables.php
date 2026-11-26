<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every record of the TeamSayo app (actions, meetings, hub records, attendance, ...) is one row.
        // Access is decided per row by App\Support\ItemPolicy.
        Schema::create('app_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('collection', 40);
            $table->string('item_key', 191);
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_key', 64)->nullable();           // person the record is about (for privacy rules)
            $table->longText('data');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'collection', 'item_key']);
            $table->index(['workspace_id', 'collection']);
            $table->index(['workspace_id', 'subject_key']);
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('person_key', 64);
            $table->string('dedupe_key', 191)->nullable();
            $table->text('text');
            $table->json('ref')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['workspace_id', 'person_key']);
            $table->unique(['workspace_id', 'dedupe_key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120);
            $table->text('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('app_items');
    }
};
