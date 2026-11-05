# Chat dos leads com Digisac

O botão flutuante **Chat**, no canto inferior direito das telas autorizadas, abre a central de atendimento em tela inteira sobre a página atual. **Minimizar** ou **Esc** retorna à página sem navegar nem perder o rascunho da conversa; ao reabrir, o acesso e o histórico são atualizados. A central também pode ser acessada diretamente em `/leads/chat`. Cada usuário possui uma conexão pessoal e vê os leads atribuídos a ele. Administração central e Gestão da unidade têm também a aba **Todas**, onde leem as conversas de toda a equipe usando o próprio token, sem poder responder: para isso, trocam o atendente na lista de leads. A aba **Disponíveis** lista os leads sem responsável das unidades autorizadas; **Assumir e abrir conversa** atribui o lead atomicamente antes de liberar o histórico. A administração continua gerenciando responsáveis na lista de leads, mas o chat também restringe gestores e administradores aos próprios atendimentos.

## Ativação

1. Aplicar as migrations: `php artisan migrate`. No Windows sem SQLite habilitado, usar `php -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate`.
2. Abrir o botão flutuante **Chat → Conexão**. O endereço padrão é `https://saofranciscopet.digisac.chat`.
3. No Digisac, gerar um **token pessoal do usuário atendente** em **menu do usuário → Conta → API → Tokens de acesso pessoal**.
4. Informar o token, o ID do usuário e o ID da conexão/canal (`serviceId`). A administração do Digisac pode fornecer esses identificadores. Clicar em **Validar e salvar conexão**.
5. A validação consulta o usuário ativo e o canal e confere se ambos pertencem à mesma conta Digisac. No vínculo feito pelo próprio atendente, o e-mail deve coincidir com o acesso local. A administração central pode escolher outro funcionário e fazer o vínculo quando os e-mails forem diferentes.
6. Fazer uma conversa de homologação com um contato autorizado: consultar histórico, enviar uma mensagem, responder pelo canal do cliente e conferir a atualização. Transferir o lead e confirmar a perda de acesso do responsável anterior.

Cada funcionário deve usar sua própria credencial pessoal. O aplicativo mantém o login local separado do login Digisac; não solicita nem sincroniza senhas. Um token com acesso restrito que não permita consultar usuário/canal precisará das permissões correspondentes no Digisac.

Tokens ficam cifrados com `APP_KEY`, ocultos na serialização e nunca são devolvidos ao navegador ou ao histórico de formulário. Preservar a chave da aplicação para continuar lendo as credenciais salvas. Trocar o endereço exige informar um novo token, para não enviar a credencial antiga a outro domínio. **Desconectar** remove a credencial local e não revoga o token nem apaga conversas no Digisac.

## Funcionamento e limites

- Mensagens são consultadas diretamente pela API v1, a cada dez segundos enquanto o chat está visível e em uso. A atualização pausa ao minimizar, com a aba escondida ou após uma hora sem interação. Não exige webhook, worker ou nova conexão Webchat.
- A lista vem dos leads locais; não importa a caixa completa de conversas do Digisac nem altera automaticamente o responsável remoto. O envio identifica o usuário Digisac configurado. A atribuição local determina o acesso neste sistema; as permissões de acesso direto ao Digisac continuam independentes.
- O telefone do lead, com país, identifica o contato dentro do canal escolhido. A consulta usa o telefone e o `serviceId`; os resultados são conferidos novamente por número exato e canal. Contatos duplicados no mesmo canal bloqueiam o atendimento até a unificação no Digisac.
- O histórico é paginado em blocos de 40 e inclui mensagens visíveis do contato/canal. Notas internas são excluídas. O envio suporta texto de até 4.096 caracteres. Imagens, áudios e outros anexos aparecem identificados e devem ser consultados no Digisac; upload, reprodução de mídia e templates não fazem parte desta versão.
- O canal continua aplicando suas regras de envio. Por exemplo, se exigir um template para iniciar a conversa, a mensagem de texto pode ser recusada; usar o Digisac para esse início.
- Cada envio usa uma chave para evitar duplicidade durante 24 horas. Não há repetição automática de POST. Se a conexão cair depois do envio, o resultado pode ser incerto: conferir o histórico antes de tentar outra mensagem. O cache deve ser persistente e compartilhado entre instâncias do servidor.
- Histórico e envio verificam unidade e responsável no servidor. Após transferência, a consulta é recusada e a interface limpa o histórico. As mensagens não são armazenadas no banco local.

## Domínios

`DIGISAC_BASE_URL` muda o endereço sugerido no formulário. `DIGISAC_ALLOWED_HOSTS` permite outros hosts exatos, separados por vírgula, quando necessários. O host informado pelo usuário já está autorizado por padrão. Subdomínios de `digisac.net` e `digisac.com.br` também são aceitos. Apenas HTTPS, sem credenciais na URL, portas, redirecionamentos, parâmetros ou caminhos arbitrários. Autorizar somente servidores confiáveis do Digisac.

## Validação

`tests/Feature/LeadChatTest.php` cobre isolamento de usuários/unidades, fila, transferência, permissões por cargo, filtros da API, destinatário e remetente definidos no servidor, token cifrado, ausência de token em formulários, configuração individual e envio sem repetição após falhas.

Os testes utilizam respostas simuladas da API. A homologação com `saofranciscopet.digisac.chat` depende do cadastro das credenciais reais em **Conexão**.

Referências: [documentação da API Digisac](https://documenter.getpostman.com/view/24605757/2sA3BhfaDg) e [tokens de acesso pessoal](https://digisac.gitbook.io/manual-digisac-2-0/menu-do-usuario/conta/token-e-webhook).
