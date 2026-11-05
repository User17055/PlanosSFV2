@extends('layouts.app')
@section('title','Chat dos leads')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/lead-chat.css').'?v='.filemtime(public_path('assets/lead-chat.css')) }}">
<div class="chat-heading"><div><h1>Chat dos leads</h1><p>Seus atendimentos, em um só lugar.</p></div><div class="chat-heading-actions"><a class="text-link" href="{{ route('leads.index') }}">Ver leads</a><a class="button button-secondary button-small" href="{{ route('leads.chat.connection') }}">@include('components.icon',['name'=>'plug']) Conexão</a></div></div>
@unless($connection?->verified_at)<div class="alert alert-info"><div>Conecte sua conta do Digisac para consultar e responder às conversas. <a href="{{ route('leads.chat.connection') }}">Configurar conexão</a></div></div>@endunless
<section class="lead-chat {{ $selected ? 'has-selection' : '' }}" aria-label="Atendimento de leads">
<aside class="chat-sidebar" aria-label="Lista de leads">
<div class="chat-list-head"><nav class="chat-tabs" aria-label="Conversas"><a href="{{ route('leads.chat.index') }}" @if(!$queue) aria-current="page" @endif>Meus leads</a><a href="{{ route('leads.chat.index',['fila'=>1]) }}" @if($queue) aria-current="page" @endif>Disponíveis</a></nav>
<form method="GET" action="{{ route('leads.chat.index') }}" role="search">@if($queue)<input type="hidden" name="fila" value="1">@endif<label class="sr-only" for="chat-search">Buscar lead no chat</label><div class="search-input">@include('components.icon',['name'=>'search'])<input id="chat-search" type="search" name="q" value="{{ $search }}" maxlength="80" placeholder="Buscar nome ou telefone"><button class="icon-button" aria-label="Buscar">@include('components.icon',['name'=>'arrow'])</button></div></form></div>
<div class="chat-lead-list">
@forelse($leads as $item)<a class="chat-lead {{ $selected?->id===$item->id ? 'is-selected' : '' }}" href="{{ route('leads.chat.show',array_filter(['lead'=>$item->id,'fila'=>$queue ? 1 : null,'q'=>$search])) }}" @if($selected?->id===$item->id) aria-current="true" @endif><span class="chat-avatar">{{ mb_strtoupper(mb_substr($item->name,0,1)) }}</span><span class="chat-lead-info"><strong>{{ $item->name }}</strong><small>{{ $item->phoneLabel() ?: 'Sem telefone' }}</small></span><span class="chat-lead-state"><i class="lead-dot is-{{ $item->status }}" aria-hidden="true"></i><small>{{ \App\Models\Lead::STATUSES[$item->status] ?? $item->status }}</small></span></a>
@empty<div class="chat-list-empty"><strong>{{ $search ? 'Nenhum resultado.' : ($queue ? 'Nenhum lead disponível.' : 'Você ainda não tem leads.') }}</strong><p>{{ $search ? 'Tente outro nome ou telefone.' : ($queue ? 'Novos leads sem responsável aparecem aqui.' : 'Assuma um lead em Disponíveis para começar.') }}</p></div>@endforelse
</div><div class="chat-list-pagination">{{ $leads->links() }}</div></aside>
<div class="chat-conversation">
@if($selected)
<header class="chat-contact"><a class="chat-back text-link" href="{{ route('leads.chat.index',['fila'=>$queue ? 1 : 0]) }}" aria-label="Voltar à lista">←</a><span class="chat-avatar">{{ mb_strtoupper(mb_substr($selected->name,0,1)) }}</span><div><h2>{{ $selected->name }}</h2><span>{{ $selected->phoneLabel() ?: 'Sem telefone' }} · {{ \App\Models\Lead::STATUSES[$selected->status] ?? $selected->status }}</span></div><span class="chat-channel">Digisac</span></header>
@if($selected->handled_by===null)
<div class="chat-empty"><span class="chat-empty-icon">@include('components.icon',['name'=>'users'])</span><h2>Este lead está disponível</h2><p>Assuma o atendimento para abrir a conversa e responder ao cliente.</p>@if(auth()->user()->canUse('leads.chat.claim') && $selected->phone)<form method="POST" action="{{ route('leads.chat.claim',$selected) }}">@csrf<button class="button">Assumir e abrir conversa</button></form>@endif</div>
@elseif(!$selected->phone)
<div class="chat-empty"><h2>Telefone não informado</h2><p>Este lead precisa de um telefone válido para conversar pelo Digisac.</p></div>
@elseif(!$connection?->verified_at)
<div class="chat-empty"><span class="chat-empty-icon">@include('components.icon',['name'=>'plug'])</span><h2>Conecte seu Digisac</h2><p>Vincule sua conta para abrir o histórico e atender este lead.</p><a class="button" href="{{ route('leads.chat.connection') }}">Configurar conexão</a></div>
@else
<div class="chat-session" data-lead-chat data-messages-url="{{ route('leads.chat.messages',$selected) }}" data-send-url="{{ route('leads.chat.send',$selected) }}">
<div class="chat-sync"><span data-chat-status role="status">Carregando conversa…</span><button type="button" class="text-button" data-chat-refresh>Atualizar</button></div>
<div class="chat-error" data-chat-error role="alert" hidden></div>
<div class="chat-history" data-chat-history tabindex="0" aria-label="Histórico da conversa"><button class="text-button chat-older" type="button" data-chat-older hidden>Carregar mensagens anteriores</button><div class="chat-messages" data-chat-messages></div><p class="chat-no-messages" data-chat-empty hidden>Nenhuma mensagem por aqui. Envie uma mensagem para iniciar a conversa.</p></div>
@if(auth()->user()->canUse('leads.chat.send'))<form class="chat-compose" data-chat-compose><label class="sr-only" for="chat-text">Mensagem para {{ $selected->name }}</label><textarea id="chat-text" name="text" rows="2" maxlength="4096" placeholder="Escreva sua mensagem…" required></textarea><button class="button" type="submit">Enviar @include('components.icon',['name'=>'arrow'])</button><small>Enter envia · Shift + Enter pula uma linha</small></form>@else<p class="chat-readonly">Seu acesso permite consultar o histórico. O envio depende da permissão de atendimento.</p>@endif
</div>
@endif
@else<div class="chat-empty"><span class="chat-empty-icon">@include('components.icon',['name'=>'phone'])</span><h2>Escolha um lead para conversar</h2><p>Abra um dos seus leads ou assuma um atendimento disponível.</p><small>@include('components.icon',['name'=>'lock']) Você acessa apenas as conversas dos seus leads.</small></div>@endif
</div></section>
@endsection
@push('scripts')<script defer src="{{ asset('assets/lead-chat.js').'?v='.filemtime(public_path('assets/lead-chat.js')) }}"></script>@endpush
