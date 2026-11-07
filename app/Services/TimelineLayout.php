<?php

namespace App\Services;

use App\Models\Task;
use App\Support\Jp;

/** 週・日タイムラインの予定ブロックの位置。重なった予定は横に並べる */
class TimelineLayout
{
    /**
     * @param  iterable<Task>  $tasks  時刻ありの予定
     * @return list<array{task:Task, s:int, e:int, lane:int, lanes:int}>
     */
    public static function lanes(iterable $tasks): array
    {
        $evs = [];
        foreach ($tasks as $t) {
            $s = Jp::toMin($t->start);
            $e = Jp::toMin($t->end);
            if ($e === null || $e <= $s) {
                $e = $s + 60;
            }
            $evs[] = ['task' => $t, 's' => $s, 'e' => min($e, 1440), 'lane' => 0, 'lanes' => 1];
        }
        usort($evs, fn ($a, $b) => $a['s'] <=> $b['s'] ?: $b['e'] <=> $a['e']);

        // 重なっているかたまりごとに、空いている列へ入れていく
        $cluster = [];
        $clusterEnd = -1;
        $flush = function () use (&$cluster, &$evs) {
            $laneEnds = [];
            foreach ($cluster as $i) {
                $lane = null;
                foreach ($laneEnds as $l => $end) {
                    if ($end <= $evs[$i]['s']) {
                        $lane = $l;
                        break;
                    }
                }
                if ($lane === null) {
                    $lane = count($laneEnds);
                }
                $laneEnds[$lane] = $evs[$i]['e'];
                $evs[$i]['lane'] = $lane;
            }
            foreach ($cluster as $i) {
                $evs[$i]['lanes'] = count($laneEnds);
            }
            $cluster = [];
        };

        foreach ($evs as $i => $ev) {
            if ($cluster && $ev['s'] >= $clusterEnd) {
                $flush();
            }
            if (! $cluster) {
                $clusterEnd = $ev['e'];
            }
            $cluster[] = $i;
            $clusterEnd = max($clusterEnd, $ev['e']);
        }
        if ($cluster) {
            $flush();
        }

        return $evs;
    }
}
