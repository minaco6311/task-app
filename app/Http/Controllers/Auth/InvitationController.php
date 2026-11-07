<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * 招待リンクからのアカウント登録（招待制。URL を知っているだけでは登録できない）
 *
 * ① GET  /invite#トークン … トークンは # の後ろなのでサーバーに送られず、アクセスログに残らない
 *                          画面の JavaScript が URL からトークンを消し、② へ POST で送る
 * ② POST /invite         … トークンを確かめ、使える招待ならセッションに覚えて ③ へ（URL にトークンは入らない）
 * ③ GET  /register       … 表示名・メールアドレス・パスワードの入力
 * ④ POST /register       … 招待を1回だけ使ってアカウントを作り、ログインした状態にする（App\Services\Registration）
 *
 * ・期限切れ・利用済み・無効化・存在しないトークンでは、アカウントは作られない
 * ・どの画面も、ブラウザ・途中の経路に保存させない（Cache-Control: no-store）。リンク元も送らない（Referrer-Policy: no-referrer）
 */
class InvitationController extends Controller
{
    /** セッションに覚える、確認できた招待 */
    private const SESSION_KEY = 'invitation';

    public function show(): BaseResponse
    {
        return $this->private(response()->view('auth.invite'));
    }

    public function redeem(Request $request): BaseResponse
    {
        $token = Str::lower(trim((string) $request->input('token')));
        $invitation = Invitation::findByToken($token);

        if (! $invitation) {
            return $this->invalid('この招待リンクは使えません');
        }
        $state = $invitation->state();
        if ($state === 'expired') {
            return $this->private(response()->view('auth.invite-expired', [], 410));
        }
        if ($state !== 'usable') {
            return $this->invalid($state === 'used' ? 'この招待リンクはすでに使われています' : 'この招待リンクは無効になっています');
        }

        // 招待を確かめたら、セッションIDを新しくして（セッション固定攻撃の対策）、招待の ID とハッシュだけを覚える
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['id' => $invitation->id, 'hash' => $invitation->token_hash]);

        return redirect()->route('register', status: 303);
    }

    public function create(Request $request): BaseResponse
    {
        $invitation = $this->sessionInvitation($request);
        if (! $invitation) {
            return $this->invalidFromSession($request);
        }

        return $this->private(response()->view('auth.register', ['invitation' => $invitation]));
    }

    public function store(Request $request, Registration $registration): BaseResponse
    {
        $invitation = $this->sessionInvitation($request);
        if (! $invitation) {
            return $this->invalidFromSession($request);
        }

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $v = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => User::passwordRules(),
        ], [
            'name.required' => '表示名を入力してください',
            'password.min' => 'パスワードは'.User::PASSWORD_MIN.'文字以上にしてください',
            'password.confirmed' => '確認のパスワードが一致しません',
        ], ['name' => '表示名', 'email' => 'メールアドレス', 'password' => 'パスワード']);

        if (! hash_equals($invitation->email, $v['email'])) {
            throw ValidationException::withMessages(['email' => '招待されたメールアドレスと違います。招待を受けたメールアドレスを入力してください']);
        }

        $result = $registration->register($invitation, $v['name'], $v['email'], $v['password']);
        if ($result === Registration::EMAIL_TAKEN) {
            throw ValidationException::withMessages(['email' => 'このメールアドレスはすでに登録されています。ログイン画面からログインしてください']);
        }
        if (! $result instanceof User) {
            // 同時に登録された・期限が切れた・無効にされた（どれもアカウントは作っていない）
            $request->session()->forget(self::SESSION_KEY);

            return $this->invalid('この招待リンクは使えなくなりました（すでに使われたか、期限が切れたか、無効にされました）');
        }

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($result);
        $request->session()->regenerate();

        return redirect()->route('calendar')->with('status', 'ようこそ。アカウントを作りました');
    }

    /** セッションに覚えた招待（まだ使えるものだけ） */
    private function sessionInvitation(Request $request): ?Invitation
    {
        $s = $request->session()->get(self::SESSION_KEY);
        if (! is_array($s) || ! isset($s['id'], $s['hash'])) {
            return null;
        }
        $invitation = Invitation::query()->whereKey($s['id'])->first();
        if (! $invitation || ! hash_equals($invitation->token_hash, (string) $s['hash']) || $invitation->state() !== 'usable') {
            return null;
        }

        return $invitation;
    }

    /** セッションの招待が使えないとき（期限切れなら期限切れの画面） */
    private function invalidFromSession(Request $request): BaseResponse
    {
        $s = $request->session()->pull(self::SESSION_KEY);
        $invitation = is_array($s) && isset($s['id']) ? Invitation::query()->whereKey($s['id'])->first() : null;
        if ($invitation?->state() === 'expired') {
            return $this->private(response()->view('auth.invite-expired', [], 410));
        }

        return $this->invalid($invitation ? 'この招待リンクは使えなくなりました' : '招待リンクを開いてから登録してください');
    }

    private function invalid(string $reason): BaseResponse
    {
        return $this->private(response()->view('auth.invite-invalid', ['reason' => $reason], 404));
    }

    /** ブラウザ・途中の経路に保存させず、リンク元も送らない */
    private function private(BaseResponse $res): BaseResponse
    {
        $res->headers->set('Cache-Control', 'no-store, private');
        $res->headers->set('Referrer-Policy', 'no-referrer');

        return $res;
    }
}
