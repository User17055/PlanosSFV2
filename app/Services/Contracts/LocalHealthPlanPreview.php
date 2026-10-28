<?php

namespace App\Services\Contracts;

use App\Models\{ProductVersion, User};
use App\Services\Domain\{Audit, DomainException};
use Illuminate\Support\Facades\DB;

class LocalHealthPlanPreview
{
    public function enable(ProductVersion $version, User $actor): ProductVersion
    {
        if (!ProductVersion::localPreviewEnabled() || !$actor->active || $actor->role!=='central_admin') {
            throw new DomainException('LOCAL_PREVIEW_ONLY','A prévia exige ambiente local demonstrativo e administração central.',403);
        }
        return DB::transaction(function() use($version,$actor) {
            $v=ProductVersion::lockForUpdate()->findOrFail($version->id);
            if ($v->status==='local_preview') return $v;
            if ($v->published_at || $v->is_demo || !hash_equals(data_get($v->contract_layout,'template_sha256',''),hash('sha256',$v->contract_template))) {
                throw new DomainException('SOURCE_CONTRACT_REQUIRED','A prévia exige um contrato real importado e ainda em rascunho.',422);
            }
            app(ContractTemplate::class)->validate($v->contract_template);
            $v->update(['status'=>'local_preview','published_at'=>now(),'published_by'=>$actor->id]);
            Audit::record('catalog_local_preview_enabled',$v,$actor,['production_enabled'=>false]);
            return $v;
        });
    }
}
