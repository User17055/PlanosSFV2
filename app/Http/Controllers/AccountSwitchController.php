<?php

namespace App\Http\Controllers;

use App\Models\{Customer, User};
use App\Services\Domain\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Hash, RateLimiter};
use Illuminate\Validation\ValidationException;

/** Quem é da administração central e também é cliente escolhe por onde entrar. */
class AccountSwitchController extends Controller
{
    public function choose(Request $request)
    {
        return view('auth.choose', ['linked' => $this->linkedCustomer($request->user())]);
    }

    public function admin()
    {
        return redirect()->intended(route('dashboard'));
    }

    public function customer(Request $request)
    {
        $admin = $request->user();
        $target = $this->linkedCustomer($admin) ?? $this->link($request, $admin);
        Audit::record('auth.switched_to_customer', $admin, $admin, ['customer_user_id' => $target->id]);
        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put('admin_return_id', $admin->id);
        return redirect()->route('portal');
    }

    public function back(Request $request)
    {
        $admin = User::find($request->session()->get('admin_return_id'));
        abort_unless($admin && $admin->active && $admin->role === 'central_admin'
            && (int) $admin->linked_customer_user_id === (int) $request->user()->id, 403, 'Volte a entrar com seu acesso da administração.');
        Auth::login($admin);
        $request->session()->regenerate();
        $request->session()->forget('admin_return_id');
        return redirect()->route('dashboard');
    }

    private function linkedCustomer(User $admin): ?User
    {
        $user = $admin->linked_customer_user_id ? User::find($admin->linked_customer_user_id) : null;
        return $user && $user->active && $user->role === 'customer' ? $user : null;
    }

    /** O vínculo exige a senha da conta de cliente: sem ela, um administrador não entra na conta de outra pessoa. */
    private function link(Request $request, User $admin): User
    {
        $data = $request->validate(['login' => 'required|string|max:255', 'password' => 'required|string|max:1000'],
            ['login.required' => 'Informe o e-mail ou o CPF da sua conta de cliente.', 'password.required' => 'Informe a senha da sua conta de cliente.']);
        $key = 'account-link:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Aguarde alguns minutos antes de tentar novamente.']);
        }
        $login = trim($data['login']);
        $customers = User::where('role', 'customer')->where('active', true);
        $candidates = str_contains($login, '@')
            ? $customers->where('email', $login)->get()
            : $customers->whereIn('customer_id', Customer::where('document', preg_replace('/\D/', '', $login))->select('id'))->limit(2)->get();
        $target = $candidates->count() === 1 ? $candidates->first() : null;
        if (! $target || ! Hash::check($data['password'], $target->password)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['password' => 'E-mail, CPF ou senha da conta de cliente incorretos.']);
        }
        if (User::where('linked_customer_user_id', $target->id)->whereKeyNot($admin->id)->exists()) {
            throw ValidationException::withMessages(['login' => 'Esta conta de cliente já está ligada a outro acesso da administração.']);
        }
        RateLimiter::clear($key);
        $admin->forceFill(['linked_customer_user_id' => $target->id])->save();
        Audit::record('auth.customer_account_linked', $admin, $admin, ['customer_user_id' => $target->id]);
        return $target;
    }
}
