# "Entrar com São Francisco" — para a Tudo pra Pet

O tutor entra na loja da Tudo pra Pet com a conta da área do cliente da São Francisco, sem criar outra senha. É OAuth 2.0 com código de autorização e PKCE (S256), o mesmo padrão dos botões "Entrar com Google".

Endereço base: `https://saude.saofrancisco.vet.br`
Cliente: `client_id = tudoprapet`. O `client_secret` é entregue por canal seguro e só pode ficar no servidor (Edge Function), nunca no navegador.

## 1. Botão "Entrar com São Francisco"

Gere um `code_verifier` aleatório (43 a 128 caracteres: letras, números, `-`, `_`) e um `state` aleatório; guarde os dois na sessão do navegador. Calcule `code_challenge = base64url(sha256(code_verifier))`, sem `=` no fim. Leve o tutor para:

```
GET https://saude.saofrancisco.vet.br/oauth/autorizar
  ?response_type=code
  &client_id=tudoprapet
  &redirect_uri=<endereço de volta cadastrado>
  &state=<state>
  &code_challenge=<code_challenge>
  &code_challenge_method=S256
```

Se ele não estiver logado, entra com e-mail ou CPF e senha. Na primeira vez, autoriza o compartilhamento; nas seguintes, volta direto.

## 2. Volta para a loja

`<redirect_uri>?code=<código>&state=<state>` — confira se o `state` é o mesmo que você guardou.
Se o tutor cancelar: `<redirect_uri>?error=access_denied&state=<state>`.

O código vale 2 minutos e uma única troca.

## 3. Trocar o código (servidor a servidor)

```
POST https://saude.saofrancisco.vet.br/oauth/token
Content-Type: application/json

{ "grant_type": "authorization_code", "code": "<código>", "redirect_uri": "<o mesmo do passo 1>",
  "code_verifier": "<code_verifier>", "client_id": "tudoprapet", "client_secret": "<segredo>" }
```

Resposta 200: `{ "access_token": "...", "token_type": "Bearer", "expires_in": 300 }`

## 4. Dados do tutor

```
GET https://saude.saofrancisco.vet.br/oauth/usuario
Authorization: Bearer <access_token>
```

Resposta 200:

```
{ "sub": "sf-123", "name": "Maria Silva", "email": "maria@email.com", "email_verified": true,
  "cpf": "12345678909", "phone": "18999990000", "plano_ativo": true }
```

- `sub` é fixo para cada tutor: use-o para ligar a conta da loja na primeira entrada (depois de achar pelo CPF ou pelo e-mail) e nas próximas.
- `plano_ativo` segue a mesma regra do grupo "Plano São Francisco" (plano em vigor e sem mensalidade em aberto depois do fim do mês). Quem está no grupo continua sendo decidido pela API do grupo; este campo é só informativo.
- Com os dados, a loja acha ou cria a conta (pelo `sub`, depois CPF, depois e-mail) e abre a sessão dela.

## Erros

| Onde | HTTP | `error` | Significado |
|---|---|---|---|
| `/oauth/autorizar` | 400 | — | `client_id` ou `redirect_uri` não cadastrados, ou parâmetro faltando (não há redirecionamento) |
| `/oauth/token` | 401 | `invalid_client` | `client_id` ou `client_secret` errados |
| `/oauth/token` | 400 | `invalid_request` | Falta `grant_type`, `code` ou `code_verifier` |
| `/oauth/token` | 400 | `invalid_grant` | Código vencido, já usado, de outro endereço de volta, ou `code_verifier` errado |
| `/oauth/usuario` | 401 | `invalid_token` | Acesso vencido (5 minutos) ou inválido |
| qualquer | 429 | — | Muitas tentativas; aguarde um minuto |

## O que a São Francisco precisa da Tudo pra Pet

- O endereço exato de volta (`redirect_uri`), por exemplo `https://tudoprapet.com.br/auth/sao-francisco`. Só endereços cadastrados recebem o tutor de volta.
