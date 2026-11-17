Olá, {{ $name }}!

Você assinou o plano de {{ $pet }}. Agora falta só o pagamento da entrada para ele começar a valer.

Plano: {{ $plan }}
Pet: {{ $pet }}
@if($amount)
Entrada: {{ $amount }}
@endif
@if($benefit)

Sua promoção: {{ $campaign }} - {{ $benefit }}, contando a entrada como a 1ª. Pague a entrada para garantir.
@endif

Pagar a entrada:
{{ $url }}

Não precisa de senha: vamos pedir só o CPF do titular.

Já pagou? É só ignorar esta mensagem.

São Francisco · Cuidado em cada fase da vida
Telefone: (18) 3221-2021
