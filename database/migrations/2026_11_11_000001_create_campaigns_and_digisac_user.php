<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campanha: N mensalidades grátis depois da entrada, para quem fecha no período.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedTinyInteger('free_months');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('whole_site')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        // A proposta guarda a campanha e os meses grátis do dia em que foi aberta: mudar a campanha depois não muda o que foi oferecido.
        // Sem chave estrangeira: no SQLite ela recria a tabela e apaga as travas de contrato assinado (campanha nunca é apagada).
        Schema::table('proposals', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->after('price_cents')->index();
            $table->unsignedTinyInteger('free_months')->default(0)->after('campaign_id');
        });
        // Quem é a pessoa no Digisac, para o lead da fila ficar com quem puxou a conversa.
        Schema::table('users', function (Blueprint $table) {
            $table->string('digisac_user_id', 100)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('digisac_user_id'));
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropColumn(['campaign_id', 'free_months']);
        });
        Schema::dropIfExists('campaigns');
    }
};
