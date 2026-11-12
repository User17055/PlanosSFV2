<?php

namespace App\Http\Controllers;

use App\Models\{Customer, User};
use App\Notifications\PortalPasswordReset;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log, Password};
use Illuminate\Support\Str;

/** Criar ou recuperar senha na própria gestão, para equipe e clientes, com o mesmo visual da tela de entrar. */
class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    /** A resposta é sempre a mesma, para não revelar quem tem cadastro. Cliente que ainda não tem senha (como os importados da Vindi) ganha o acesso aqui. */
    public function email(Request $request)
    {
        $email = mb_strtolower(trim((string) $request->validate(['email' => 'required|email|max:180'])['email']));
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user) {
            $user = Customer::whereRaw('lower(email) = ?', [$email])->where('active', true)->orderBy('id')->first()?->accessUser();
        }
        if ($user?->active) {
            try {
                Password::sendResetLink(['email' => $user->email], fn (User $user, string $token) => $user->notify(new PortalPasswordReset(route('password.reset', ['token' => $token, 'email' => $user->email]))));
            } catch (\Throwable $e) {
                Log::warning('password_reset_mail_failed', ['error_type' => $e::class, 'reason' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
        return back()->with('status', 'Se houver um cadastro com esse e-mail, você vai receber o link em alguns minutos. Confira também a caixa de spam.');
    }

    public function edit(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => mb_substr((string) $request->query('email'), 0, 180)]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:200', 'email' => 'required|email|max:180', 'password' => 'required|string|min:12|max:1000|confirmed'],
            ['password.min' => 'Use pelo menos 12 caracteres.', 'password.confirmed' => 'A confirmação não é igual à senha.']);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('portal_tokens')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Link inválido ou vencido. Peça um novo link.']);
        }
        return redirect()->route('login', ['login' => $data['email']])->with('success', 'Senha salva. Entre com seu e-mail e a nova senha.');
    }
}
