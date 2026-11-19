<dialog class="modal" id="seller-{{ $proposalId }}"><form method="POST" action="{{ route('proposals.seller',$proposalId) }}">@csrf
<div class="modal-heading"><h2>Quem fez a venda</h2><button type="button" class="icon-button" data-dialog-close aria-label="Fechar">@include('components.icon',['name'=>'close'])</button></div>
<p>Proposta {{ $number }}. Use quando um mandou o link e outro fechou: a venda e a comissão ainda não paga passam para quem você escolher. A troca fica registrada.</p>
<label class="field">Quem fez a venda <span>*</span><select name="seller_id" required>@foreach($staff as $person)<option value="{{ $person->id }}" @selected($person->id===$current)>{{ $person->name }}</option>@endforeach</select></label>
<div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Voltar</button><button class="button button-primary" data-submit-label="Salvando...">Salvar</button></div>
</form></dialog>
