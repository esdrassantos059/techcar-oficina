<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marca_id')->index()->constrained('marca')->restrictOnDelete();
            $table->foreignId('pessoa_id')->index()->constrained('pessoa')->restrictOnDelete();
            $table->string('modelo');
            $table->string('placa', 7)->unique();
            $table->smallInteger('ano');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carro');
    }
};
