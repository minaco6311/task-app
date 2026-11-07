<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 7) 同じ招待リンクで「同時に」登録しても、アカウントは1つだけ
 * 別々の PHP のプロセスを何個も起動し、合図でいっせいに登録させる（本物の同時実行）
 * プロセスどうしで同じ DB を見る必要があるので、テスト用 MySQL（sh tests/mysql/run.sh）でだけ動かす
 */
class InvitationConcurrencyTest extends TestCase
{
    private const RACERS = 8;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('同時登録はテスト用 MySQL で確かめます（sh tests/mysql/run.sh）');
        }
        Artisan::call('migrate:fresh', ['--force' => true]); // 別のプロセスから見えるように、データを確定させて使う
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql') {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    public function test_simultaneous_registrations_with_one_invitation_create_one_account(): void
    {
        $inv = new Invitation;
        $inv->forceFill(['email' => 'race@example.com', 'token_hash' => Invitation::hashToken(Invitation::newToken()), 'expires_at' => now()->addDays(7)])->save();
        $barrier = sys_get_temp_dir().'/irodori-race-'.bin2hex(random_bytes(6));

        $procs = [];
        for ($i = 0; $i < self::RACERS; $i++) {
            $p = proc_open(['php', base_path('tests/mysql/register-race.php'), (string) $inv->id, 'race@example.com', $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $procs[] = [$p, $pipes];
        }
        usleep(1_500_000); // 全員が起動して合図を待つまで
        touch($barrier);

        $results = [];
        foreach ($procs as [$p, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]));
            $err = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($p), $err);
        }
        @unlink($barrier);

        sort($results);
        $this->assertSame(1, count(array_keys($results, 'created', true)), implode(',', $results));
        $this->assertEmpty(array_diff($results, ['created', 'email_taken', 'unavailable']));
        $this->assertSame(1, User::count());
        $user = User::query()->sole();
        $inv->refresh();
        $this->assertSame('used', $inv->state());
        $this->assertSame($user->id, $inv->used_by);
        $this->assertSame(6, Owner::run($user, fn () => Category::count())); // 初期の凡例も1回分だけ
    }
}
