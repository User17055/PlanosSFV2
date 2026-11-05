@extends('layouts.chat')
@section('title','Chat dos leads')
@section('content')
@php
$initials=fn($name)=>mb_strtoupper(collect(preg_split('/\s+/',trim(preg_replace('/[^\p{L}\p{N}\s]/u','',$name))))->filter()->take(2)->map(fn($word)=>mb_substr($word,0,1))->implode(''));
$tone=fn($name)=>crc32($name)%6;
$statusLabel=fn($lead)=>\App\Models\Lead::STATUSES[$lead->status] ?? $lead->status;
@endphp
@unless($connection?->verified_at)<div class="alert alert-info"><div>Conecte sua conta do Digisac para consultar e responder às conversas. <a href="{{ route('leads.chat.connection') }}">Configurar conexão</a></div></div>@endunless
<section class="lead-chat {{ $selected ? 'has-selection' : '' }}" aria-label="Atendimento de leads">
<aside class="chat-sidebar" aria-label="Lista de leads">
<div class="chat-list-head"><div class="chat-list-title"><h1>Conversas</h1><a class="chat-connection-link {{ $connection?->verified_at ? 'is-on' : '' }}" href="{{ route('leads.chat.connection') }}" title="{{ $connection?->verified_at ? 'Digisac conectado' : 'Digisac não conectado' }}"><i aria-hidden="true"></i>Conexão</a></div><nav class="chat-tabs" aria-label="Conversas"><a href="{{ route('leads.chat.index') }}" @if(!$queue && !$all) aria-current="page" @endif>Meus leads</a><a href="{{ route('leads.chat.index',['fila'=>1]) }}" @if($queue) aria-current="page" @endif>Disponíveis</a>@if($seesAll)<a href="{{ route('leads.chat.index',['todas'=>1]) }}" @if($all) aria-current="page" @endif>Todas</a>@endif</nav>
<form method="GET" action="{{ route('leads.chat.index') }}" role="search">@if($queue)<input type="hidden" name="fila" value="1">@endif @if($all)<input type="hidden" name="todas" value="1">@endif<label class="sr-only" for="chat-search">Buscar lead no chat</label><div class="search-input">@include('components.icon',['name'=>'search'])<input id="chat-search" type="search" name="q" value="{{ $search }}" maxlength="80" placeholder="Buscar nome ou telefone"><button class="icon-button" aria-label="Buscar">@include('components.icon',['name'=>'arrow'])</button></div></form></div>
<div class="chat-lead-list">
@forelse($leads as $item)<a class="chat-lead {{ $selected?->id===$item->id ? 'is-selected' : '' }}" href="{{ route('leads.chat.show',array_filter(['lead'=>$item->id,'fila'=>$queue ? 1 : null,'todas'=>$all ? 1 : null,'q'=>$search])) }}" @if($selected?->id===$item->id) aria-current="true" @endif><span class="chat-avatar is-tone-{{ $tone($item->name) }}" aria-hidden="true">{{ $initials($item->name) }}</span><span class="chat-lead-info"><strong>{{ $item->name }}</strong><span class="chat-lead-meta"><small>{{ $all ? 'Com '.$item->handler?->name : ($item->phoneLabel() ?: 'Sem telefone') }}</small><span class="chat-status is-{{ $item->status }}">{{ $statusLabel($item) }}</span></span></span></a>
@empty<div class="chat-list-empty"><strong>{{ $search ? 'Nenhum resultado.' : ($queue ? 'Nenhum lead disponível.' : ($all ? 'Nenhuma conversa em atendimento.' : 'Você ainda não tem leads.')) }}</strong><p>{{ $search ? 'Tente outro nome ou telefone.' : ($queue ? 'Novos leads sem responsável aparecem aqui.' : ($all ? 'Quando alguém da equipe assumir um lead, ele aparece aqui.' : 'Assuma um lead em Disponíveis para começar.')) }}</p></div>@endforelse
</div><div class="chat-list-pagination">{{ $leads->links() }}</div></aside>
<div class="chat-conversation">
@if($selected)
<header class="chat-contact"><a class="chat-back text-link" href="{{ route('leads.chat.index',array_filter(['fila'=>$queue ? 1 : null,'todas'=>$all ? 1 : null])) }}" aria-label="Voltar à lista">←</a><span class="chat-avatar is-tone-{{ $tone($selected->name) }}" aria-hidden="true">{{ $initials($selected->name) }}</span><div class="chat-contact-info"><h2>{{ $selected->name }}</h2><div class="chat-contact-line"><span>{{ $selected->phoneLabel() ?: 'Sem telefone' }}</span><span class="chat-status is-{{ $selected->status }}">{{ $statusLabel($selected) }}</span>@if($selected->handled_by && $selected->handled_by!==auth()->id())<span class="chat-status is-owner">Com {{ $selected->handler?->name }}</span>@endif</div></div><span class="chat-channel"><i aria-hidden="true"></i>Digisac</span></header>
@if($selected->handled_by===null)
<div class="chat-empty"><span class="chat-empty-icon">@include('components.icon',['name'=>'users'])</span><h2>Este lead está disponível</h2><p>Assuma o atendimento para abrir a conversa e responder ao cliente.</p>@if(auth()->user()->canUse('leads.chat.claim') && $selected->phone)<form method="POST" action="{{ route('leads.chat.claim',$selected) }}">@csrf<button class="button button-primary">Assumir e abrir conversa</button></form>@endif</div>
@elseif(!$selected->phone)
<div class="chat-empty"><h2>Telefone não informado</h2><p>Este lead precisa de um telefone válido para conversar pelo Digisac.</p></div>
@elseif(!$connection?->verified_at)
<div class="chat-empty"><span class="chat-empty-icon">@include('components.icon',['name'=>'plug'])</span><h2>Conecte seu Digisac</h2><p>Vincule sua conta para abrir o histórico e atender este lead.</p><a class="button button-primary" href="{{ route('leads.chat.connection') }}">Configurar conexão</a></div>
@else
<div class="chat-session" data-lead-chat data-messages-url="{{ route('leads.chat.messages',$selected) }}" data-send-url="{{ route('leads.chat.send',$selected) }}">
<div class="chat-sync"><span data-chat-status role="status">Carregando conversa…</span><button type="button" class="text-button" data-chat-refresh>Atualizar</button></div>
<div class="chat-error" data-chat-error role="alert" hidden></div>
<div class="chat-history" data-chat-history tabindex="0" aria-label="Histórico da conversa"><button class="text-button chat-older" type="button" data-chat-older hidden>Carregar mensagens anteriores</button><div class="chat-messages" data-chat-messages></div><p class="chat-no-messages" data-chat-empty hidden>Nenhuma mensagem por aqui. Envie uma mensagem para iniciar a conversa.</p></div>
@if($selected->handled_by!==auth()->id())<p class="chat-readonly">Você está acompanhando esta conversa. Para responder, troque o atendente nos dados do lead, na lista de leads.</p>@elseif(auth()->user()->canUse('leads.chat.send'))<form class="chat-compose" data-chat-compose><label class="sr-only" for="chat-text">Mensagem para {{ $selected->name }}</label><textarea id="chat-text" name="text" rows="1" maxlength="4096" placeholder="Escreva sua mensagem…" required></textarea><button class="button button-primary" type="submit" aria-label="Enviar mensagem"><span data-send-label>Enviar</span>@include('components.icon',['name'=>'arrow'])</button><small>Enter envia · Shift + Enter pula uma linha</small></form>@else<p class="chat-readonly">Seu acesso permite consultar o histórico. O envio depende da permissão de atendimento.</p>@endif
</div>
@endif
@else<div class="chat-empty"><span class="chat-empty-icon">@include('components.icon',['name'=>'phone'])</span><h2>Escolha um lead para conversar</h2><p>Abra um dos seus leads ou assuma um atendimento disponível.</p><small>@include('components.icon',['name'=>'lock']) {{ $seesAll ? 'Como administrador, você acompanha as conversas de toda a equipe em Todas.' : 'Você acessa apenas as conversas dos seus leads.' }}</small></div>@endif
</div></section>
@endsection
@push('scripts')<script defer src="{{ asset('assets/lead-chat.js').'?v='.filemtime(public_path('assets/lead-chat.js')) }}"></script>@endpush
