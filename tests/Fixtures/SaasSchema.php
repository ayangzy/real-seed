<?php

namespace Ayangzy\RealSeed\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small SaaS-style application covering the structures the analyzer must handle:
 * a nullable cycle (organizations <-> users), a self-reference (users.manager_id),
 * a pivot without FK constraints (project_user), polymorphic comments, a
 * polymorphic many-to-many (taggables), a PHP enum cast, and a database enum.
 */
final class SaasSchema
{
    public static function create(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('manager_id')->nullable()->constrained('users');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('users');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->decimal('budget', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->primary(['project_id', 'user_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->string('title');
            $table->enum('status', ['todo', 'in_progress', 'done', 'cancelled'])->default('todo');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->morphs('commentable');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained();
            $table->morphs('taggable');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->text('payload');
        });
    }
}
