# Integração São Francisco → Tudo pra Pet (assinantes)

Texto para enviar ao desenvolvedor da Tudo pra Pet.

## Contexto

A São Francisco vende planos de saúde para pets (Super Econômico, Econômico, Essencial e Prime). Quando o cliente **paga e confirma o e-mail**, o plano é ativado e nosso sistema precisa **cadastrar a pessoa na Tudo pra Pet como assinante**, para ela ter os preços exclusivos. Se o plano for suspenso, cancelado ou terminar, o benefício precisa ser retirado.

Nosso sistema chama a API de vocês (somente servidor para servidor). Os descontos são calculados no lado de vocês; nós não controlamos preço.

## O que precisamos que a API ofereça

### 1. Autenticação
- Chave de API exclusiva da São Francisco, enviada em `Authorization: Bearer <chave>`, com permissão só para assinantes.
- Ambiente de **teste (sandbox)** separado, com chave própria.
- Tudo por HTTPS.

### 2. Criar ou atualizar assinante (idempotente)
`PUT /api/parceiros/sao-francisco/assinantes/{id_externo}`

`id_externo` é nosso identificador estável (ex.: `sf-123`). Repetir a chamada **não pode duplicar** o cadastro: atualiza.

Corpo:
```json
{
  "cliente": {
    "nome": "Maria da Silva",
    "cpf": "52998224725",
    "email": "maria@exemplo.com",
    "telefone": "18999990000",
    "endereco": { "cep": "19000000", "rua": "Rua A", "numero": "10", "bairro": "Centro", "cidade": "Presidente Prudente", "uf": "SP" },
    "senha_hash": "$2y$12$..."
  },
  "pet": { "nome": "Lua", "especie": "dog" },
  "plano": { "codigo": "SF-ECONOMICO", "nome": "Plano Econômico", "ativado_em": "2026-10-30T14:00:00-03:00" }
}
```
- `especie`: `dog` ou `cat`. Códigos de plano: `SF-SUPER-ECONOMICO`, `SF-ECONOMICO`, `SF-ESS`, `SF-PRIME`.
- Se a pessoa **já existe** (mesmo CPF ou e-mail), associar o plano à conta existente, sem criar outra e **sem trocar a senha dela**.

Resposta (200/201):
```json
{ "id": "tpp_9981", "situacao": "criado|atualizado|ja_existia", "grupo": "assinante-sao-francisco" }
```
Erros em JSON claro: `400` dados inválidos, `401` chave inválida, `409` conflito (ex.: CPF e e-mail apontam para contas diferentes), `422` regra de negócio, `429` limite, `5xx` temporário. Com `codigo` e `mensagem` legíveis.

### 3. Senha
Queremos que a pessoa entre na Tudo pra Pet com a **mesma senha** do cadastro São Francisco. Nós **não temos a senha em texto**, só o hash (bcrypt, formato `$2y$`). Por favor, informem qual destas é possível:
- **(a)** aceitar `senha_hash` em bcrypt (preferido); ou
- **(b)** criar a conta sem senha e enviar um convite para a pessoa definir a senha.

### 4. Suspender e encerrar
- `POST /api/parceiros/sao-francisco/assinantes/{id_externo}/suspender` (inadimplência): mantém a conta, retira o grupo/desconto.
- `POST /api/parceiros/sao-francisco/assinantes/{id_externo}/reativar`
- `POST /api/parceiros/sao-francisco/assinantes/{id_externo}/cancelar`: retira o benefício; a conta continua como cliente comum.

Os três também idempotentes.

### 5. Consulta
`GET /api/parceiros/sao-francisco/assinantes/{id_externo}` → situação atual (`ativo|suspenso|cancelado`) e id da Tudo pra Pet. Só consideramos "concluído" depois de uma resposta de sucesso de vocês.

### 6. Opcional
Webhook da Tudo pra Pet para nós, quando a pessoa apagar a conta ou mudar CPF/e-mail lá.

## Perguntas para vocês
1. Qual o endereço base de produção e de teste?
2. Como é a chave de API (formato, validade, troca)?
3. A senha pode ir como hash bcrypt? Qual o custo/algoritmo que vocês usam?
4. O que "assinante" significa aí: grupo, nível, cupom? Pode ter regras diferentes por plano?
5. Há limite de chamadas por minuto?
6. Como ficam LGPD e exclusão de dados: vocês são operadores ou controladores desses dados? Precisamos alinhar o aviso de privacidade.

## O que fazemos do nosso lado
- Enviamos a chamada da seção 2 na **primeira ativação** do plano, com tentativas automáticas em falha (a chamada é idempotente).
- Enviamos suspender, reativar e cancelar quando o plano muda de situação.
- Guardamos o `id` de vocês e a resposta, e só marcamos como concluído com sucesso confirmado.
