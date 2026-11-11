Olá, {{ $name }}!

Você assinou o plano de {{ $pet }}. Agora falta só o pagamento da entrada para ele começar a valer.

Plano: {{ $plan }}
Pet: {{ $pet }}
@if($amount)
Entrada: {{ $amount }}
@endif
@if($freeMonths)

Sua promoção: {{ $campaign }} - {{ $freeMonths }} {{ $freeMonths === 1 ? 'mensalidade grátis' : 'mensalidades grátis' }} depois da entrada. Pague a entrada para garantir.
@endif

Pagar a entrada:
{{ $url }}

Não precisa de senha: vamos pedir só o CPF do titular.

Já pagou? É só ignorar esta mensagem.

São Francisco · Cuidado em cada fase da vida
Telefone: (18) 3221-2021
