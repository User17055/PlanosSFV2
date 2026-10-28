<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AccessService
{
    public function franchiseIds(User $user): array
    {
        $ids = DB::table('user_franchises')->where('user_id', $user->id)->where('active', true)->pluck('franchise_id')->all();
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function scope(Builder $query, User $user, string $column = 'originating_franchise_id'): Builder
    {
        return $user->role === 'central_admin' ? $query : $query->whereIn($column, $this->franchiseIds($user));
    }

    public function franchise(User $user, int $id): void
    {
        abort_unless($user->role === 'central_admin' || in_array($id, $this->franchiseIds($user), true), 403, 'Unidade não autorizada.');
        abort_unless(DB::table('franchises')->where('id', $id)->where('active', true)->exists(), 422, 'Unidade inativa.');
    }

    public function customerQuery(User $user): Builder
    {
        $query = Customer::query();
        if ($user->role === 'central_admin') return $query;
        if ($user->role === 'customer') return $query->whereKey($user->customer_id);
        $ids = $this->franchiseIds($user);
        return $query->where(function (Builder $q) use ($ids) {
            $q->whereIn('originating_franchise_id', $ids)
                ->orWhereHas('proposals', fn (Builder $p) => $p->whereIn('originating_franchise_id', $ids))
                ->orWhereHas('subscriptions', fn (Builder $s) => $s->whereIn('originating_franchise_id', $ids));
        });
    }

    public function customer(User $user, Customer $customer): void
    {
        abort_unless($this->customerQuery($user)->whereKey($customer->id)->exists(), 403, 'Cadastro fora do seu escopo de acesso.');
    }

    public function record(User $user, object $record): void
    {
        if ($user->role === 'customer') {
            abort_unless((int) $record->customer_id === (int) $user->customer_id, 403, 'Registro de outro titular.');
            return;
        }
        if ($user->role !== 'central_admin') {
            $id = $record->originating_franchise_id ?? $record->servicing_franchise_id;
            abort_unless(in_array((int) $id, $this->franchiseIds($user), true), 403, 'Registro de outra unidade.');
        }
    }
}
