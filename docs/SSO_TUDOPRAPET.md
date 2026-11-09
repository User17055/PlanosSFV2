# "Entrar com São Francisco" — para a Tudo pra Pet

O tutor entra na loja da Tudo pra Pet com a conta da área do cliente da São Francisco, sem criar outra senha. É **OpenID Connect** (código de autorização), o mesmo padrão dos botões "Entrar com Google".

## Dados para cadastrar o provedor (Lovable Cloud / Supabase)

| Campo | Valor |
|---|---|
| Issuer / endereço de descoberta | `https://saude.saofrancisco.vet.br` → `https://saude.saofrancisco.vet.br/.well-known/openid-configuration` |
| Client ID | `tudoprapet` |
| Client Secret | entregue por canal seguro; só no servidor, nunca no navegador |
| Escopos | `openid email profile phone` |
| Endereço de volta cadastrado | `https://tudoprapet.com.br/auth/sao-francisco` |

Com o endereço de descoberta, o Lovable Cloud acha sozinho todos os outros: autorização, token, dados do usuário e chaves públicas (`jwks`).

- Assinatura do `id_token`: RS256; chaves públicas em `https://saude.saofrancisco.vet.br/oauth/jwks`.
- Envio do segredo no token: `client_secret_post` ou `client_secret_basic`.
- PKCE (S256) é aceito e recomendado, mas não é obrigatório. Se o `code_challenge` for enviado, o `code_verifier` é exigido na troca.
- `nonce` é aceito e volta dentro do `id_token`.
- Só o endereço de volta cadastrado recebe o tutor. Se o Lovable Cloud usar outro endereço (por exemplo, `https://<projeto>.supabase.co/auth/v1/callback`), avise para cadastrarmos.

## Fluxo

1. A loja leva o tutor para `GET /oauth/autorizar?response_type=code&client_id=tudoprapet&redirect_uri=…&scope=openid%20email%20profile%20phone&state=…&nonce=…` (mais `code_challenge` e `code_challenge_method=S256`, se usar PKCE).
2. Se não estiver logado, ele entra com e-mail ou CPF e senha. Na primeira vez, autoriza o compartilhamento; nas seguintes, volta direto.
3. Volta para `<redirect_uri>?code=…&state=…`. Se o tutor cancelar, volta para `<redirect_uri>?error=access_denied&state=…`. O código vale 2 minutos e uma única troca.
4. A loja faz `POST /oauth/token` (servidor a servidor) com `grant_type=authorization_code`, `code`, `redirect_uri` e as credenciais (mais `code_verifier`, se usou PKCE). Recebe `access_token`, `id_token`, `token_type: "Bearer"` e `expires_in: 300`.
5. Opcionalmente, a loja chama `GET /oauth/usuario` com `Authorization: Bearer <access_token>`, que devolve os mesmos dados do `id_token`.

## Dados do tutor (no `id_token` e em `/oauth/usuario`)

```
{ "sub": "sf-123", "name": "Maria Silva", "email": "maria@email.com", "email_verified": true,
  "phone_number": "+5518999990000", "cpf": "12345678909", "plano_ativo": true }
```

- `sub` é fixo para cada tutor: use-o para ligar a conta da loja na primeira entrada e nas próximas.
- **Entrar não libera o preço exclusivo.** `plano_ativo` é só informativo. Quem tem o preço continua sendo decidido apenas pela API do grupo "Plano São Francisco" (ativar, desativar, status).
- O `id_token` traz também `iss` (`https://saude.saofrancisco.vet.br`), `aud` (`tudoprapet`), `iat`, `exp` (5 minutos) e o `nonce`, quando enviado.

## Erros

| Onde | HTTP | `error` | Significado |
|---|---|---|---|
| `/oauth/autorizar` | 400 | — | `client_id` ou `redirect_uri` não cadastrados, ou parâmetro inválido (não há redirecionamento) |
| `/oauth/token` | 401 | `invalid_client` | `client_id` ou `client_secret` errados |
| `/oauth/token` | 400 | `invalid_request` | Falta `grant_type` ou `code` |
| `/oauth/token` | 400 | `invalid_grant` | Código vencido, já usado, de outro endereço de volta, ou `code_verifier` errado |
| `/oauth/usuario` | 401 | `invalid_token` | Acesso vencido (5 minutos) ou inválido |
| qualquer | 429 | — | Muitas tentativas; aguarde um minuto |
