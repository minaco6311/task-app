<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * パスワードの変更（ログイン中の本人だけ。だれのパスワードを変えるかはフォームから受け取らない）
 * ・いまのパスワードを確かめる（間違いは1分に6回まで）
 * ・変えたら、ほかの端末のログインを切る（DB のセッションを消し、「ログインしたままにする」の情報も作り直す）
 */
class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('settings.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [...User::passwordRules(), 'different:current_password'],
        ], [
            'current_password.required' => 'いまのパスワードを入力してください',
            'current_password.current_password' => 'いまのパスワードが違います',
            'password.min' => '新しいパスワードは'.User::PASSWORD_MIN.'文字以上にしてください',
            'password.confirmed' => '確認のパスワードが一致しません',
            'password.different' => 'いまと違うパスワードにしてください',
        ], ['current_password' => 'いまのパスワード', 'password' => '新しいパスワード']);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['password' => $v['password'], 'remember_token' => Str::random(60)])->save();

        // この端末はセッションIDを新しくして続けて使い、ほかの端末のセッションは消す
        $request->session()->regenerate(true);
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return redirect()->route('settings.edit')->with('status', 'パスワードを変えました（ほかの端末ではログインし直しが必要です）');
    }
}
