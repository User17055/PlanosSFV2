@php
$acquisitionCustomer=$acquisitionCustomer??null;
$acquisitionProfile=(array)$acquisitionCustomer?->acquisition_profile;
$acquisitionFailed=$errors->getBag($acquisitionBag??'default')->any();
$acquisitionValue=fn($field)=>$acquisitionFailed?old($field,$acquisitionProfile[$field]??''):($acquisitionProfile[$field]??'');
$acquisitionOptions=['referral'=>'Indicação de alguém','instagram'=>'Instagram','facebook'=>'Facebook','google'=>'Google','shared_link'=>'Recebi um link','direct'=>'Já conhecia a São Francisco','other'=>'Outra forma'];
@endphp
<div class="form-divider"></div><div class="form-section-heading"><span class="quick-icon tone-blue">@include('components.icon',['name'=>'link'])</span><div><h2>Como conheceu a São Francisco?</h2><p>Conte como chegou até nós e se alguém recomendou nossos planos.</p></div></div>
<div class="form-grid">
<label class="field">Como conheceu?<select name="acquisition_source"><option value="">Selecione, se souber</option>@foreach($acquisitionOptions as $value=>$label)<option value="{{ $value }}" @selected($acquisitionValue('acquisition_source')===$value)>{{ $label }}</option>@endforeach</select></label>
<label class="field">Quem recomendou?<input name="referrer_name" maxlength="180" value="{{ $acquisitionValue('referrer_name') }}" placeholder="Nome de quem indicou, se houver"><small>Pode informar uma indicação mesmo que tenha vindo por uma rede social.</small></label>
@if(auth()->check() && auth()->user()->role!=='customer')<label class="field">Campanha ou parceria<input name="campaign_name" maxlength="120" value="{{ $acquisitionValue('campaign_name') }}" placeholder="Evento, empresa parceira ou campanha"></label>@endif
</div>
