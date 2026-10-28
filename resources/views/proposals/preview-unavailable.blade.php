@extends('layouts.app')
@section('title','Contrato temporariamente indisponível')
@section('content')
<section class="card form-card"><h1>O contrato não carregou agora.</h1><p>{{ $message }}</p><div class="form-actions"><a class="button button-primary" href="{{ route('proposals.preview-pdf',$proposal) }}">Tentar abrir novamente</a><a class="button button-secondary" href="{{ route('proposals.show',$proposal) }}">Voltar à contratação</a></div></section>
@endsection
