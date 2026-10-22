<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_hikvision_enrollment_work', static function (Blueprint $table): void {
            $table->foreignId('student_id')->primary()->constrained('ec_students')->restrictOnDelete();
            $table->unsignedBigInteger('requested_version')->default(0);
            $table->unsignedBigInteger('processed_version')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->uuid('lease_token')->nullable();
            $table->unsignedBigInteger('lease_until')->default(0);
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain durable enrollment work; use a reviewed code/config rollback, not a queue wipe.');
    }
};
