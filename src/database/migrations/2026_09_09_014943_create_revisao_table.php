<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carro_id')->index()->constrained('carro')->restrictOnDelete();
            $table->foreignId('pessoa_id')->index()->constrained('pessoa')->restrictOnDelete();
            $table->date('data_revisao')->index();
            $table->text('descricao');
            $table->decimal('valor', 10, 2);
            $table->enum('status', ['pendente', 'em_andamento', 'concluida', 'cancelada'])
                ->default('pendente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisao');
    }
};
