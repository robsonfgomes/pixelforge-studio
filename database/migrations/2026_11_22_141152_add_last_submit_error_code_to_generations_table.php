<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Why the last submit attempt got no 202: Pixelforge's code, `overloaded` or `uncertain`.
            $table->string('last_submit_error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('last_submit_error_code');
        });
    }
};
