@if($errors->getBag($bag)->any())
<div class="alert alert-danger" role="alert"><div><strong>Confira os campos deste formulário.</strong><ul>@foreach($errors->getBag($bag)->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div></div>
@endif
