@if(auth()->check() && in_array(auth()->user()->role,\App\Models\User::AREAS['leads'][2],true) && auth()->user()->canOpen('leads') && auth()->user()->canUse('leads.chat.index'))
<link rel="stylesheet" href="{{ asset('assets/lead-chat-launcher.css').'?v='.filemtime(public_path('assets/lead-chat-launcher.css')) }}">
@php $chatWaiting=($counts['leads_new'] ?? 0)>0; @endphp
<a class="lead-chat-launcher" href="{{ route('leads.chat.index') }}" title="Chat dos leads" data-chat-launcher aria-haspopup="dialog" aria-controls="lead-chat-window" aria-expanded="false" aria-label="Abrir chat dos leads{{ $chatWaiting ? ', há leads esperando atendimento' : '' }}">@include('components.chat-icon')<i class="lead-chat-dot" data-chat-dot{{ $chatWaiting ? '' : ' hidden' }} aria-hidden="true"></i></a>
<dialog class="lead-chat-window" id="lead-chat-window" aria-labelledby="lead-chat-window-title" data-chat-window>
<header class="lead-chat-window-bar"><div>@include('components.chat-icon')<strong id="lead-chat-window-title">Central de atendimento</strong></div><button type="button" class="lead-chat-minimize" data-chat-minimize aria-label="Minimizar chat" autofocus>@include('components.icon',['name'=>'down'])<span>Minimizar</span></button></header>
<iframe title="Chat dos leads" data-chat-frame></iframe>
</dialog>
<script defer src="{{ asset('assets/lead-chat-launcher.js').'?v='.filemtime(public_path('assets/lead-chat-launcher.js')) }}"></script>
@endif
