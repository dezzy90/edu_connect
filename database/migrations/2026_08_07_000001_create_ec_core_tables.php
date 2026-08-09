<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_system', 'source_id']);
        });

        Schema::create('ec_schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ec_tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->index();
            $table->string('code')->nullable()->index();
            $table->string('type')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->default('Africa/Douala');
            $table->string('logo_path')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->default('local')->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('settings')->nullable();
            $table->json('mobile_settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->unique(['source_system', 'source_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_schools');
        Schema::dropIfExists('ec_tenants');
    }
};
