@php
$contractCustomer = $contractCustomer ?? null;
$contractAddress = $contractCustomer?->address ?? [];
$contractAddress = is_array($contractAddress) ? $contractAddress : [];
$contractBag = $contractBag ?? 'default';
$contractFailed = $errors->getBag($contractBag)->any();
$contractValue = fn($field, $value = null) => $contractFailed ? old($field, $value) : $value;
@endphp
<label class="field">RG<input name="rg" maxlength="40" value="{{ $contractValue('rg', $contractCustomer?->rg) }}"></label>
<input type="hidden" name="_structured_address" value="1">
<label class="field">CEP <span>*</span><input name="zip" required inputmode="numeric" maxlength="9" pattern="[0-9]{5}-?[0-9]{3}" autocomplete="postal-code" data-postal-lookup="{{ url('/enderecos/cep') }}" value="{{ $contractValue('zip', $contractAddress['zip'] ?? $contractAddress['cep'] ?? '') }}" placeholder="00000-000"><small data-postal-status role="status" aria-live="polite">Comece pelo CEP para preencher a rua, o bairro e a cidade.</small></label>
<label class="field">Rua / avenida <span>*</span><input name="street" required maxlength="200" autocomplete="address-line1" value="{{ $contractValue('street', $contractAddress['street'] ?? '') }}" placeholder="Preenchida pelo CEP; você pode corrigir"></label>
<label class="field">Número <span>*</span><input name="number" required maxlength="30" autocomplete="address-line2" value="{{ $contractValue('number', $contractAddress['number'] ?? '') }}" placeholder="Informe o número ou S/N"><small>O número deve ser informado por você.</small></label>
<label class="field">Complemento<input name="complement" maxlength="120" value="{{ $contractValue('complement', $contractAddress['complement'] ?? '') }}" placeholder="Apartamento, bloco, casa…"></label>
@if($contractCustomer && empty($contractAddress['street']) && !empty($contractAddress['formatted']))<p class="fine-print span-2">Endereço atual: {{ $contractAddress['formatted'] }}. Confira o CEP e informe a rua e o número nos campos acima para atualizar.</p>@endif
<label class="field">Bairro<input name="neighborhood" maxlength="150" autocomplete="address-level3" value="{{ $contractValue('neighborhood', $contractAddress['neighborhood'] ?? '') }}"></label>
<label class="field">Cidade<input name="city" maxlength="150" autocomplete="address-level2" value="{{ $contractValue('city', $contractAddress['city'] ?? '') }}"></label>
<label class="field">Estado (UF)<input name="state" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="address-level1" value="{{ $contractValue('state', $contractAddress['state'] ?? '') }}"></label>
@once @push('scripts')<script defer src="{{ asset('assets/postal-address.js') }}?v={{ filemtime(public_path('assets/postal-address.js')) }}"></script>@endpush @endonce
