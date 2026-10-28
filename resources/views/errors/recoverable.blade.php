<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $status===419?'Atualize seu acesso':'Vamos retomar' }} · São Francisco</title><link rel="stylesheet" href="{{ asset('assets/mint.css') }}?v={{ filemtime(public_path('assets/mint.css')) }}"></head>
<body class="public-body"><main class="public-main"><section class="card form-card error-recovery">
<img src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco" width="130">
<h1>{{ $status===419?'Sua sessão precisa ser atualizada.':'Não foi possível concluir esta etapa agora.' }}</h1>
<p>{{ $status===419?'Abra a página novamente e entre com seu acesso, se necessário.':'Retome suas propostas para verificar o que já foi registrado antes de tentar novamente.' }}</p>
<p>Se a assinatura já foi confirmada, ela permanece no histórico. Acompanhe a preparação do PDF na proposta.</p>
<div class="form-actions"><a class="button button-primary" href="{{ route('proposals.index') }}">Retomar minhas propostas</a><a class="button button-secondary" href="{{ route('home') }}">Ir para o início</a></div>
</section></main></body></html>
