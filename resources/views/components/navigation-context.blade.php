@php
$currentMenu=collect($navGroups)->flatMap(fn($group)=>$group[1])->first(fn($item)=>$item[0]===$activeRoute);
// "Início" já é a página inicial do perfil: evita "Início / Meu cuidado / Meu cuidado".
if($currentMenu && route($currentMenu[0])===$homeRoute) $currentMenu=null;
// Nem repete o item do menu quando ele é a própria página: "Minhas propostas / Minhas propostas".
if($currentMenu && $currentMenu[2]===trim($__env->yieldContent('title','Visão geral'))) $currentMenu=null;
@endphp
<nav class="navigation-context" aria-label="Sua localização"><a href="{{ $homeRoute }}">Início</a>@if($currentMenu)<span aria-hidden="true">/</span><a href="{{ route($currentMenu[0]) }}">{{ $currentMenu[2] }}</a>@endif<span aria-hidden="true">/</span><strong aria-current="location">@yield('title','Visão geral')</strong></nav>

