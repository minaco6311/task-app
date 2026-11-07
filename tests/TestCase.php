<?php

namespace Tests;

use App\Support\Owner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Owner::reset();

        // テストは DB を作り直す（RefreshDatabase）ので、開発用・本番の MySQL では絶対に動かさない
        // 動かしてよいのは SQLite のメモリ上の DB と、使い捨てのテスト用 MySQL（tests/mysql/run.sh・名前が _test で終わる DB）だけ
        $conn = config('database.default');
        $db = (string) config("database.connections.{$conn}.database");
        $host = (string) config("database.connections.{$conn}.host");
        $ok = ($conn === 'sqlite' && $db === ':memory:')
            || ($conn === 'mysql' && str_ends_with($db, '_test') && $host === 'mysqltest-db');
        if (! $ok) {
            throw new RuntimeException("テスト用ではない DB（{$conn}）に接続しようとしたため止めました");
        }
    }

    protected function tearDown(): void
    {
        Owner::reset();
        parent::tearDown();
    }
}
