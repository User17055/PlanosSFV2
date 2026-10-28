@if($version->benefitRules->isNotEmpty())
<div class="product-coverages">
    <div class="product-benefits product-benefits-preview">
        @foreach($version->benefitRules->take(3) as $rule)
        <div><span class="benefit-check">@include('components.icon',['name'=>'check'])</span><div><strong>{{ $rule->name }}</strong></div></div>
        @endforeach
    </div>
    <details class="product-coverages-details">
        <summary><span class="coverages-more">Ver mais</span><span class="coverages-less">Ver menos</span>@include('components.icon',['name'=>'down'])</summary>
        <div class="product-benefits">
            @foreach($version->benefitRules as $rule)
            <div><span class="benefit-check">@include('components.icon',['name'=>'check'])</span><div><strong>{{ $rule->name }}</strong>@include('components.benefit-summary',['rule'=>$rule])</div></div>
            @endforeach
        </div>
    </details>
</div>
@endif
