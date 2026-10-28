@extends('layouts.app')
@section('title','Modelo do contrato')
@section('content')
<a class="back-link" href="{{ route('catalog.index') }}">← Planos e pacotes</a>
<div class="page-heading"><div><h1>Modelo do contrato</h1><p>{{ $version->name }} · versão {{ $version->version }}</p></div></div>
<p>Este é o texto utilizado na geração do contrato. Os campos entre chaves recebem os dados do tutor e do pet de cada contratação, inclusive na identificação da assinatura.</p>
@if($version->contract_layout)
<a class="button button-primary" href="{{ route('catalog.template-pdf',$version) }}" target="_blank" rel="noopener">Abrir contrato em PDF</a>
@endif
<section class="card form-card"><div class="contract-text">{{ $text }}</div></section>
@endsection
