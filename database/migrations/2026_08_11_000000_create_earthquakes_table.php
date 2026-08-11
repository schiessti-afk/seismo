<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('earthquakes', function (Blueprint $table) {
            $table->id();
            $table->string('usgs_id')->unique();
            $table->decimal('magnitude', 4, 2)->nullable();
            $table->string('mag_type')->nullable();
            $table->text('place')->nullable();
            $table->float('depth_km')->nullable();
            $table->boolean('tsunami')->default(false);
            $table->string('status')->nullable();
            $table->string('url')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('usgs_updated_at')->nullable();
            $table->timestampTz('recorded_at');
            $table->json('raw');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE earthquakes ADD COLUMN location geography(Point, 4326) NOT NULL');
        DB::statement('CREATE INDEX earthquakes_location_gist ON earthquakes USING GIST (location)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('earthquakes');
    }
};
