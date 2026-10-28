# Identidade visual São Francisco

Referência conferida novamente em **22/10/2026**, no fuso America/Sao_Paulo: [site institucional São Francisco](https://saofrancisco.vet.br/).

A identidade do aplicativo foi alinhada aos arquivos públicos do próprio site. As cores e a tipografia abaixo foram lidas no HTML/CSS; o logotipo foi baixado e inspecionado visualmente. O aplicativo mantém seus componentes e fluxos de gestão.

## Fontes primárias

- [Página institucional](https://saofrancisco.vet.br/): referência ao logotipo /imgs/logo.png?v=0.0.6, ao favicon e à família Poppins do Google Fonts.
- [CSS institucional](https://saofrancisco.vet.br/css/estilo.css?vr=3.0&v=0.0.6): cores, tipografia e tratamento dos elementos.
- [Logotipo oficial](https://saofrancisco.vet.br/imgs/logo.png?v=0.0.6): arquivo PNG transparente, 178 × 127 pixels, preservado em public/assets/brand/sao-francisco-logo.png. Contém o mascote canino e a placa azul “São Francisco”. Não foi redesenhado, recolorido ou substituído por um símbolo genérico.
- [Favicon oficial](https://saofrancisco.vet.br/imgs/favicon.png?v=0.0.6): preservado em public/assets/brand/favicon.png.

## Paleta extraída do site

| Uso no site | Cor exata | Evidência no CSS institucional |
| --- | --- | --- |
| Cabeçalho fixado | #14264B | header.active |
| Seções escuras | #0D1C39, #0B1936 | Fundos de seções e rodapé |
| Texto e botões azuis | #08649A | Botões do banner e conteúdo |
| Azul complementar | #0B5988 | Botões e títulos de seções |
| Destaques e chamada à ação | #83AC38 | nav .btn-acao, títulos e traço do banner |
| Azul claro | #C2E4EB | Gradiente dos botões e seções |
| Azul acinzentado claro | #DEE8EA | Segundo ponto do mesmo gradiente |

Os tokens correspondentes estão no CSS local: --brand-navy, --brand-navy-deep, --brand-blue, --brand-blue-deep, --brand-lime, --brand-sky e --brand-mist.

O fundo de trabalho #F4F8FA, o texto de apoio #52677E e as bordas #D7E3EB são derivações funcionais para a interface administrativa, e não cores alegadamente extraídas do site. As cores semânticas de sucesso, pendência e erro continuam distintas, com rótulos textuais.

## Tipografia local

O CSS institucional declara **font-family: 'Poppins', sans-serif**; o HTML solicita os pesos 300, 400 e 700.

O aplicativo usa **Poppins** em toda a interface. Foram incluídos os pesos 200, 300, 400, 500, 600 e 700 para contemplar os componentes existentes sem depender de síntese de pesos (o 200 atende aos números grandes e finos do tema claro). Os arquivos vêm do [serviço oficial Google Fonts](https://fonts.googleapis.com/css2?family=Poppins:wght@200;300;400;500;600;700&display=swap), e são servidos localmente:

| Peso | Arquivo local | URL original |
| --- | --- | --- |
| 200 | poppins-200.ttf | https://fonts.gstatic.com/s/poppins/v24/pxiByp8kv8JHgFVrLFj_V1s.ttf |
| 300 | poppins-300.ttf | https://fonts.gstatic.com/s/poppins/v24/pxiByp8kv8JHgFVrLDz8V1s.ttf |
| 400 | poppins-400.ttf | https://fonts.gstatic.com/s/poppins/v24/pxiEyp8kv8JHgFVrFJA.ttf |
| 500 | poppins-500.ttf | https://fonts.gstatic.com/s/poppins/v24/pxiByp8kv8JHgFVrLGT9V1s.ttf |
| 600 | poppins-600.ttf | https://fonts.gstatic.com/s/poppins/v24/pxiByp8kv8JHgFVrLEj6V1s.ttf |
| 700 | poppins-700.ttf | https://fonts.gstatic.com/s/poppins/v24/pxiByp8kv8JHgFVrLCz7V1s.ttf |

Todos estão em public/assets/brand/. A [licença SIL Open Font License da Poppins](https://github.com/google/fonts/blob/main/ofl/poppins/OFL.txt) foi incluída como Poppins-OFL.txt. O CSS não importa mais fontes externas; os arquivos usam font-display: swap.

## Aplicação

A estrutura clara do tema **Mint**, com painéis brancos, menu recolhível e animações, permanece. Em 22/10/2026 a paleta foi corrigida para acompanhar o CSS institucional da São Francisco, substituindo o verde-água anterior:

- Topo e menu lateral em azul-marinho `#14264B`, com superfícies profundas `#0B1936`.
- Botões principais e seleção do menu em azul `#08649A`; hover e links em `#0B5988`.
- Verde-lima `#83AC38` nos destaques e indicadores de sucesso, com texto escurecido para leitura.
- Fundo de trabalho claro `#F4F8FA`, títulos azul-marinho e textos de apoio `#52677E`.
- Gráficos: vendas em azul, recebimentos em verde-lima e comissões pagas em azul-marinho. A legenda usa exatamente as mesmas cores.
- Mapas: áreas sem planos em cinza; intensidade crescente do azul claro `#C2E4EB` ao azul-marinho `#14264B`, conforme a quantidade de planos ativos. Os cartões de bairros seguem o azul da marca.
- Alertas de pendência e erro mantêm cores próprias e rótulos textuais.

As variáveis de cor estão em `public/assets/mint.css`. Os SVGs dos gráficos e mapas usam a mesma paleta em `public/assets/analytics.js`. Logotipo, fonte Poppins, estrutura de navegação e animações foram preservados.

## Verificação estática

As seguintes combinações do tema claro foram calculadas com a fórmula de luminância relativa WCAG:

| Combinação | Contraste |
| --- | --- |
| Branco sobre botão azul #08649A | 6,36:1 |
| Branco sobre topo e menu #14264B | 14,91:1 |
| Link #0B5988 sobre branco | 7,50:1 |
| Texto de sucesso #4E681F sobre fundo #F4F8FA | 5,91:1 |
| Texto de apoio #52677E sobre branco | 5,83:1 |

Os arquivos locais referenciados no CSS existem; o CSS apresenta chaves balanceadas e nenhum erro de análise no parser tinycss2. Foram mantidos os estados de foco visíveis, os rótulos, os formulários e as regras de acesso existentes. Esta etapa não alterou controladores, rotas ou regras de negócio.

Inspeção visual do arquivo original do logotipo concluída. A revisão desta etapa não utilizou automação alternativa de navegador; a conferência funcional final pertence ao roteiro de testes do projeto.

## Camada visual e movimento

Os layouts carregam um único tema, sem etapa de compilação. `app.css`, `theme.css` e `analytics.css` continuam no disco, mas não são mais carregados.

- `public/assets/mint.css`: tema claro inspirado no BlurAdmin Mint (cores, tipografia, painéis, formulários, tabelas, selos, modais, painel de resultados, mapa, site público e login).
- `public/assets/shell.js`: casca da área autenticada. No desktop, o botão de menu recolhe a barra lateral para ícones (o nome aparece como dica) e a escolha fica salva no navegador; no celular, o mesmo botão abre o menu. Também escurece a sombra do topo ao rolar e fecha o menu do usuário ao clicar fora ou com Esc.
- O topo traz busca de tutores (somente equipe; usa a mesma rota de Tutores e pets) e o menu do usuário com atalhos e saída.
- A paginação usa a view `resources/views/vendor/pagination/tailwind.blade.php`, com rótulos em português.
- `public/assets/motion.js`: animações com [GSAP 3.15.0](https://gsap.com) (núcleo, ScrollTrigger e SplitText), servido localmente em `public/assets/vendor/gsap/` a partir do pacote oficial `gsap@3.15.0` do npm, sob a [licença padrão GSAP](https://gsap.com/standard-license).

Efeitos aplicados: entrada em cascata do conteúdo, revelação ao rolar, contagem dos indicadores numéricos, indicador do menu que desliza entre páginas, título do site animado por palavra, ilustrações flutuantes, parallax no hero, linha de progresso em "Juntos em cada passo", barra de progresso de leitura, onda ao clicar em botões e transições do assistente de contratação. Os cartões ficam parados; nos cartões de produto, quem reage é o botão (brilho ao passar pelo cartão, seta que dá a volta e leve atração ao cursor).

Acessibilidade e segurança de renderização:

- Com `prefers-reduced-motion: reduce`, nenhuma animação de entrada ou repetição é executada.
- O conteúdo só fica oculto enquanto `html.motion-pending` existir. O próprio layout remove essa classe após 3 segundos, então uma falha no carregamento do GSAP não deixa a página em branco.
- Os textos animados pelo SplitText mantêm `aria-label` com a frase completa.
- Conteúdo que aguarda a rolagem aparece imediatamente ao chegar ao fim da página, ao navegar por âncora, ao receber foco pelo teclado ou ao imprimir.

## Telas e dispositivos

O tema claro foi verificado por captura de tela em 1440 px e 390 px. Faixas tratadas em `mint.css`: até 1280 px, 1100 px, 1024 px (menu lateral vira gaveta), 768 px (celular) e 520 px (celular pequeno).

- Nenhum bloco de conteúdo é ocultado por tamanho de tela. Painéis laterais (link seguro da proposta, contatos do tutor, atalhos, orientações) passam para baixo do conteúdo principal.
- No celular, as tabelas viram cartões: `public/assets/app.js` copia o título de cada coluna para as células (`data-label`), sem alterar as views.
- Campos de formulário usam 16 px no celular para evitar o zoom automático do iPhone; botões têm área de toque de pelo menos 40 px.
- Tabelas largas, como as dos relatórios, rolam na horizontal dentro do painel em telas médias.


## Navegação sem tremido

O "tremido" ao trocar de página vinha da fonte Poppins sendo baixada de novo a cada página, porque o servidor local não enviava cabeçalhos de cache: o texto aparecia na fonte provisória e pulava ao trocar para a Poppins. Não era causado pelas animações.

- `server.php` (desenvolvimento) e `docs/ops/nginx.conf` (produção) servem `/assets/` com `Cache-Control` e `ETag`.
- Os layouts pré-carregam os quatro pesos mais usados (300, 400, 500 e 600).
- `scrollbar-gutter: stable` reserva o espaço da barra de rolagem, evitando deslocamento lateral entre páginas curtas e longas.

Medição anterior ao tema claro, em navegação real entre Painel, Tutores, Catálogo e Propostas: deslocamento de layout (CLS) passou a 0 a partir da segunda página. Ainda não foi repetida com o tema claro.
