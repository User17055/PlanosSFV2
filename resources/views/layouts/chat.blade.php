<!doctype html>
<html lang="pt-BR" class="chat-document">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title','Chat dos leads') · São Francisco</title>
<link rel="icon" type="image/png" href="{{ asset('assets/brand/favicon.png') }}">
<link rel="stylesheet" href="{{ asset('assets/mint.css').'?v='.filemtime(public_path('assets/mint.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/lead-chat.css').'?v='.filemtime(public_path('assets/lead-chat.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/lead-chat-window.css').'?v='.filemtime(public_path('assets/lead-chat-window.css')) }}">
<script>if(window.self!==window.top)document.documentElement.classList.add('chat-embedded');</script>
<script defer src="{{ asset('assets/lead-chat-window.js').'?v='.filemtime(public_path('assets/lead-chat-window.js')) }}"></script>
</head>
<body class="chat-window-body">
<header class="chat-window-topbar"><strong>Central de atendimento</strong><a class="text-link" href="{{ route('leads.index') }}">Voltar ao sistema</a></header>
<main class="chat-window-content">
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><div><strong>Confira as informações para continuar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
@yield('content')
</main>
@stack('scripts')
</body></html>
