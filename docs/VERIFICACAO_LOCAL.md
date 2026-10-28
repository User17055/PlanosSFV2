# Verificação da entrega local

Data da entrega: 18/10/2026, fuso America/Sao_Paulo.

- PHP 8.4.6, Laravel 13.37.0, Dompdf 3.1.6; dependências fixadas em composer.lock.
- Suíte completa: 44 testes, 182 verificações aprovadas.
- Após ajuste da apresentação de elegibilidade no perfil: teste de páginas aprovado com 14 verificações.
- Login real por HTTP com sessão e CSRF validado; painel, tutores, catálogo, propostas, autorizações, financeiro, integrações e rede responderam HTTP 200.
- CSS e JavaScript responderam HTTP 200; sintaxe JavaScript e templates PHP compilados verificados.
- Download privado do contrato respondeu HTTP 200 application/pdf após autenticação.
- Auditoria Composer sem avisos de vulnerabilidades conhecidos na consulta realizada.
- Duas páginas do PDF conferidas visualmente; benefícios legíveis, sem cortes ou sobreposição.
- Revisão visual interativa da aplicação no navegador indisponível: a ferramenta retornou inventário vazio.

A demonstração utiliza SQLite e dados fictícios. Concorrência no MySQL, restauração na VPS, SMTP, MFA e operações reais de Vindi/SimplesVet/TudoPraPet permanecem sujeitos à homologação indicada nos demais documentos.

Para abrir: Iniciar Sistema.cmd ou .\start.ps1. Endereço local padrão: http://127.0.0.1:8765. Filas e agendador: .\worker.ps1 e .\worker.ps1 -Scheduler em terminais separados.

## Atualização de 19/10/2026 — identidade e relatórios

- Interface alinhada ao site institucional, com logotipo original, Poppins local e paleta azul/verde documentada em IDENTIDADE_VISUAL.md. CSS analisado sem erros; contrastes principais calculados e assets locais conferidos.
- Suíte completa: **74 testes e 369 verificações aprovadas**, executada com PHPUnit diretamente e SQLite em memória. Cobre sessão de aquisição atravessando login/aceite, isolamento por franquia, perfis, expiração/revogação de links, responsáveis separados, indicadores e CSV.
- Login real com sessão/CSRF; painel, relatórios, links de divulgação, nova proposta e exportação responderam HTTP 200. Exportação retornou text/csv; filtro por responsável pelo link e logotipo presentes no HTML. CSS, fonte e logotipo responderam HTTP 200.
- Migrações de aquisição e reparo aplicadas ao banco existente, sem reinicialização nem recriação de registros comerciais. Conferência de sqlite_master confirmou os oito gatilhos de propostas/assinaturas, incluindo imutabilidade da atribuição, preservação contratual e datas de ativação.
- A reconstrução de tabelas do SQLite descartava os gatilhos anteriores; a migração de aquisição agora os reinstala, e uma migração de reparo também cobre instalações já atualizadas. Regressões verificam os nomes reais e os códigos de bloqueio SQL com referências válidas.
- Navegação visual interativa continua indisponível: a ferramenta de navegador retornou inventário vazio. Foram feitas verificações estáticas, de templates e HTTP; não se afirma uma revisão visual das telas renderizadas.

O rastreamento passa a guardar novas origens. Registros sem evidência anterior continuam como Não informado; acessos de links são eventos, não pessoas únicas.

## Atualização de 19/10/2026 — ficha única, financeiro e comissões

- Suíte completa: **95 testes e 476 verificações aprovadas**.
- A ficha administrativa do tutor reúne dados do tutor, pets, planos/contratos e propostas em sequência vertical. Cadastro inicial cria tutor e primeiro pet na mesma transação; links contextuais reutilizam os registros existentes ao iniciar uma proposta.
- O financeiro deixou de depender de uma tabela larga: cada fatura apresenta todos os dados e ações em um registro adaptável. Rótulos pagos e de conciliação foram corrigidos para “Paga” e “Verificada”.
- Menu administrativo sem indicador deslizante, barra lateral verde, transição de página ou tela animada de carregamento. CSS e JavaScript analisados sem erros.
- Comissões por produto aceitam percentual ou valor fixo, surgem no primeiro pagamento integral conciliado e preservam quem fez a proposta, responsável pelo link, canal, origem, campanha e indicação. Contestação bloqueia; cancelamento ou estorno reverte.
- Contratação sem pessoa da equipe identificada é registrada como “Sem responsável comissionado”, com valor zero. Nenhum responsável ou percentual é inventado para o histórico.
- HTTP autenticado confirmou `200` em Financeiro, Ficha do tutor, Comissões e Nova proposta com tutor/pet preselecionados. As respostas não contêm os antigos elementos de efeito de navegação.
- A ferramenta de navegador permaneceu indisponível; a validação de corte foi estrutural, responsiva por CSS, automatizada e por HTTP, sem afirmar inspeção visual renderizada.
