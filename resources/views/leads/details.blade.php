@php
$words=fn($value)=>\Illuminate\Support\Str::ucfirst(str_replace('_',' ',$value));
$when=fn($date)=>$date?->timezone('America/Sao_Paulo')->format('d/m/Y H:i');
$answers=collect($extra)->except(['anuncio','formulario','trafego',...\App\Models\Lead::CHANNEL_FIELDS])->mapWithKeys(fn($value,$key)=>[$key==='especie_do_pet' ? 'Espécie' : $words($key)=>$words($value)])->all();
$groups=[
    'De onde veio'=>['Rede'=>$origin['label'] ?? 'Não informada','Tráfego'=>$lead->traffic(),'Campanha'=>$lead->campaign && !ctype_digit((string) $lead->campaign) ? $lead->campaignLabel() : null,'Anúncio'=>$extra['anuncio'] ?? null,'Formulário'=>$extra['formulario'] ?? null],
    'Dados do formulário'=>['E-mail'=>$lead->email,'Pet'=>$lead->pet_name]+$answers,
    // O sistema guarda só a última pessoa que mudou a situação, não o histórico.
    'Atendimento'=>['Chegou'=>$when($lead->created_at),'Situação'=>\App\Models\Lead::STATUSES[$lead->status] ?? $lead->status,'Atendido por'=>$lead->handler?->name ?? 'Ninguém ainda','Primeiro contato'=>$when($lead->contacted_at),'Última mudança'=>$lead->handler ? $when($lead->updated_at) : null],
];
@endphp
<div class="lead-more-grid">
@foreach(array_filter(array_map('array_filter',$groups)) as $title=>$rows)
<div><h3>{{ $title }}</h3><dl>@foreach($rows as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl></div>
@endforeach
</div>
