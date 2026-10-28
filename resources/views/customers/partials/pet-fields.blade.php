@php
$prefix=$prefix ?? '';
$editing=$editing ?? false;
$failed=$errors->getBag($bag)->any();
$petSex=match($pet?->sex) {'male','macho'=>'male','female','fêmea'=>'female',default=>'unknown'};
$fieldValue=fn($field,$fallback=null)=>$failed?old($prefix.$field,$fallback):$fallback;
@endphp
<div class="workspace-fields">
    <label class="field">Nome do pet <span>*</span><input name="{{ $prefix }}name" required maxlength="100" value="{{ $fieldValue('name',$pet?->name) }}" placeholder="Nome do pet"></label>
    @if(!$editing)
    <label class="field">Espécie <span>*</span><select name="{{ $prefix }}species" required>
        <option value="">Selecione a espécie</option><option value="dog" @selected($fieldValue('species')==='dog')>Cão</option><option value="cat" @selected($fieldValue('species')==='cat')>Gato</option>
    </select></label>
    @endif
    <label class="field">Raça<input name="{{ $prefix }}breed" maxlength="100" value="{{ $fieldValue('breed',$pet?->breed) }}" placeholder="Raça ou sem raça definida"></label>
    <label class="field">Cor / pelagem<input name="{{ $prefix }}color" maxlength="100" value="{{ $fieldValue('color',$pet?->color) }}"></label>
    <label class="field">Data de nascimento <span>*</span><input name="{{ $prefix }}birth_date" type="date" required max="{{ now('America/Sao_Paulo')->format('Y-m-d') }}" value="{{ $fieldValue('birth_date',$pet?->birth_date?->format('Y-m-d')) }}"></label>
    <label class="field">Sexo<select name="{{ $prefix }}sex"><option value="unknown" @selected($fieldValue('sex',$petSex)==='unknown')>Não informado</option><option value="male" @selected($fieldValue('sex',$petSex)==='male')>Macho</option><option value="female" @selected($fieldValue('sex',$petSex)==='female')>Fêmea</option></select></label>
    @if(!$editing)<label class="field">Microchip, se houver<input name="{{ $prefix }}microchip" maxlength="100" value="{{ $fieldValue('microchip') }}"><small>Ajuda a localizar o mesmo pet e evitar cadastros duplicados.</small></label>@endif
</div>
