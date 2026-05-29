<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('dictionary_values');
        Schema::dropIfExists('dictionary_types');
    }

    public function down(): void {}
};
