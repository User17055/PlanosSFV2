@extends('layouts.chat')
@section('title','Conexão com o Digisac')
@section('content')
<div class="chat-heading"><div><h1>Conexão com o Digisac</h1><p>Vincule o atendimento à conta de {{ $target->name }}.</p></div><a class="text-link" href="{{ route('leads.chat.index') }}">Voltar ao chat</a></div>
<section class="card chat-settings">
@if($users->isNotEmpty())<form method="GET" action="{{ route('leads.chat.connection') }}" class="chat-target"><label for="chat-user">Configurar para</label><div><select id="chat-user" name="user_id">@foreach($users as $person)<option value="{{ $person->id }}" @selected($target->id===$person->id)>{{ $person->name }}</option>@endforeach</select><button class="button button-secondary button-small">Selecionar</button></div></form>@endif
@if($connection?->verified_at)<div class="chat-connection-status"><i></i><div><strong>Conexão validada</strong><p>{{ $connection->remote_name }} · {{ $connection->verified_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</p></div></div>@else<p class="chat-connection-note">Use um token pessoal do usuário que fará o atendimento. A senha do Digisac não é necessária.</p>@endif
<form method="POST" action="{{ route('leads.chat.connection.save') }}" class="chat-settings-form" autocomplete="off">@csrf<input type="hidden" name="user_id" value="{{ $target->id }}">
<label>Endereço do Digisac<input type="url" name="base_url" value="{{ old('base_url',$connection?->base_url ?? config('digisac.base_url')) }}" placeholder="https://saofranciscopet.digisac.chat" required maxlength="255"><small>O mesmo endereço usado para entrar na plataforma.</small></label>
<label>Token de acesso pessoal<input type="password" name="api_token" autocomplete="new-password" maxlength="4096" @required(!$connection) placeholder="{{ $connection ? 'Preencha somente para substituir o token salvo' : 'Cole o token gerado no Digisac' }}"><small>No Digisac: menu do usuário → Conta → API → Tokens de acesso pessoal. O token fica criptografado e não é exibido após salvar.</small></label>
<label>ID do usuário no Digisac<input type="text" name="digisac_user_id" value="{{ old('digisac_user_id',$connection?->digisac_user_id) }}" required maxlength="100"><small>Identificador do atendente que assinará os envios. @if(auth()->user()->role!=='central_admin')O e-mail no Digisac deve ser {{ $target->email }}. Se for diferente, peça o vínculo à administração central.@endif</small></label>
<label>ID da conexão no Digisac<input type="text" name="service_id" value="{{ old('service_id',$connection?->service_id) }}" required maxlength="100"><small>Identificador do canal usado para falar com os leads. A administração do Digisac pode fornecer os dois IDs.</small></label>
<div><button class="button button-primary">Validar e salvar conexão</button></div>
</form>
@if($connection)<form method="POST" action="{{ route('leads.chat.connection.destroy') }}" class="chat-disconnect">@csrf<input type="hidden" name="user_id" value="{{ $target->id }}"><button class="text-button text-danger" data-confirm="Remover esta conexão do sistema?">Desconectar do sistema</button><small>As conversas permanecem no Digisac.</small></form>@endif
</section>
@endsection
