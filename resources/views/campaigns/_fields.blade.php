<label class="field">Nome<input name="name" required maxlength="120" value="{{ $campaign?->name }}" placeholder="Ex.: 1 mensalidade grátis"></label>
<label class="field">Meses grátis<input type="number" name="free_months" required min="1" max="12" value="{{ $campaign?->free_months ?? 1 }}"></label>
<label class="field">Começa em<input type="date" name="starts_on" required value="{{ $campaign?->starts_on?->toDateString() ?? now('America/Sao_Paulo')->toDateString() }}"></label>
<label class="field">Termina em<input type="date" name="ends_on" value="{{ $campaign?->ends_on?->toDateString() }}"></label>
@unless($campaign)<label class="check-label"><input type="checkbox" name="whole_site" value="1"><span>Campanha principal (vale no site todo)</span></label>@endunless
