<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users');
            $table->string('plan', 20)->default('trial');          // trial | team | business
            $table->string('cycle', 10)->nullable();                 // monthly | annual
            $table->string('status', 20)->default('trialing');     // trialing | active | past_due | cancelled | expired
            $table->timestamp('trial_ends_at')->nullable();
            $table->unsignedInteger('seats')->default(0);           // paid seats (0 during trial = unlimited up to trial cap)
            $table->boolean('nonprofit')->default(false);
            $table->string('sector', 60)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);                               // stable id used by the app (person.org)
            $table->string('name');
            $table->string('color', 16)->default('#0b8f8f');
            $table->timestamps();
            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);                              // owner | admin | director | manager | employee | auditor
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('person_key', 64);                        // the person record this login belongs to
            $table->string('title')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();    // another membership
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
            $table->unique(['workspace_id', 'person_key']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->unique(['team_id', 'membership_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('person_key', 64);
            $table->string('title')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('workspaces');
    }
};
