<label class="field">Nome<input name="name" required maxlength="120" value="{{ $campaign?->name }}" placeholder="Ex.: Black Friday"></label>
<label class="field">Mensalidades grátis<input type="number" name="free_months" required min="0" max="12" value="{{ $campaign?->free_months ?? 1 }}"></label>
<label class="field">Desconto depois (%)<input type="number" name="discount_percent" min="0" max="100" value="{{ $campaign?->discount_percent ?? 0 }}"></label>
<label class="field">Mensalidades com desconto<input type="number" name="discount_months" min="0" max="12" value="{{ $campaign?->discount_months ?? 0 }}"></label>
<label class="field">Começa em<input type="date" name="starts_on" required value="{{ $campaign?->starts_on?->toDateString() ?? now('America/Sao_Paulo')->toDateString() }}"></label>
<label class="field">Termina em<input type="date" name="ends_on" value="{{ $campaign?->ends_on?->toDateString() }}"></label>
@unless($campaign)<label class="check-label"><input type="checkbox" name="whole_site" value="1"><span>Campanha principal (vale no site todo)</span></label>@endunless
@include('campaigns._flow',['campaign'=>$campaign])
