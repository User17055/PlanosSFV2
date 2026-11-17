<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Última mensagem do contato no Digisac, para a lista do chat (leads:digisac-chat). chat_unread é zero quando a equipe respondeu por último.
            $table->timestamp('chat_last_at')->nullable()->index();
            $table->json('chat_last')->nullable();
            $table->unsignedInteger('chat_unread')->default(0);
            // Quando o atendente abriu a conversa no sistema: o que chegou antes disso já foi visto.
            $table->timestamp('chat_read_at')->nullable();
            $table->timestamp('chat_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['chat_last_at']);
            $table->dropColumn(['chat_last_at', 'chat_last', 'chat_unread', 'chat_read_at', 'chat_synced_at']);
        });
    }
};
