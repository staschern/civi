<?php
declare(strict_types=1);

namespace Civi;

/**
 * Раскладка пары деревьев по правилу сквозных столбцов.
 *
 * Тот же алгоритм, что в прототипе доски, но на семени: одно и то же семя
 * даёт одну и ту же раскладку, поэтому версию можно пересобрать по коду.
 *
 * Правила:
 *   - у эпохи от 2 до 3 столбцов, третий добавляется случайно и только
 *     если карточек хватает на две в столбце;
 *   - технологии одной категории внутри эпохи расходятся по разным
 *     столбцам слева направо, поэтому внутри ветки хронология не путается;
 *   - столбцы нумеруются сквозной нумерацией через все эпохи;
 *   - технология без зависимостей возможна только в первом столбце
 *     первой эпохи;
 *   - любая другая опирается минимум на одну технологию из непосредственно
 *     предыдущего столбца;
 *   - для первого столбца эпохи правило смягчено: обычно связь идёт
 *     в последний столбец предыдущей эпохи, иногда — глубже в прошлое;
 *   - порядок внутри столбца считается методом барицентров.
 */
final class TreeGenerator
{
    public const LANE_MIN = 2;
    public const LANE_MAX = 3;
    private const LANE_P0 = 0.55;
    private const LANE_DECAY = 0.5;
    private const P_PREV_ERA = 0.78;
    private const P_SECOND = 0.3;

    /**
     * @param array $eras       [['id'=>int,'position'=>int], …] по возрастанию position
     * @param array $techs      [['id'=>int,'era_id'=>int,'branch_id'=>int], …] одного дерева
     * @param array $prereqs    [tech_id => [prereq_tech_id, …]] авторские связи каталога
     * @param int   $treeSeed   семя раскладки этого дерева
     * @param array $laneCounts [era_id => число столбцов] — общее решение по эпохам
     *
     * @return array{nodes: array, links: array}
     *   nodes: [tech_id => ['era_id','lane','row','global_column','relaxed']]
     *   links: [['from'=>tech_id,'to'=>tech_id,'origin'=>string], …]
     */
    public function generate(array $eras, array $techs, array $prereqs, int $treeSeed, array $laneCounts): array
    {
        $rng = new Rng($treeSeed);

        // сквозная нумерация столбцов и раскидка технологий по ним
        $columns = [];          // gi => ['era_id','lane','nodes'=>[tech_id, …]]
        $firstColOfEra = [];
        foreach ($eras as $era) {
            $firstColOfEra[$era['id']] = count($columns);
            $lanes = $laneCounts[$era['id']] ?? self::LANE_MIN;
            for ($lane = 0; $lane < $lanes; $lane++) {
                $columns[] = ['era_id' => $era['id'], 'lane' => $lane, 'nodes' => []];
            }
        }

        $byId = [];
        $techsByEra = [];
        $this->branchOf = [];
        foreach ($techs as $t) {
            $byId[$t['id']] = $t;
            $techsByEra[$t['era_id']][] = $t['id'];
            $this->branchOf[$t['id']] = $t['branch_id'];
        }

        $node = [];             // tech_id => ['col','lane','row','relaxed']
        foreach ($eras as $era) {
            $inEra = $techsByEra[$era['id']] ?? [];
            $lanes = max(1, min($laneCounts[$era['id']] ?? self::LANE_MIN, count($inEra) ?: 1));

            // Технологии одной категории идут по разным столбцам слева
            // направо в порядке каталога: так шаги одной ветки не оказываются
            // в одном столбце и внутри ветки сохраняется хронология.
            $groups = [];
            foreach ($inEra as $techId) {
                $groups[$this->branchOf[$techId]][] = $techId;
            }
            // сначала длинные группы: им нужнее свободные подряд идущие столбцы
            uasort($groups, static function ($a, $b) {
                return count($b) <=> count($a);
            });

            $load = array_fill(0, $lanes, 0);
            $assigned = array_fill(0, $lanes, []);
            foreach ($groups as $group) {
                $size = min(count($group), $lanes);
                // стартовый столбец — тот, с которого группа ляжет ровнее всего
                $bestStart = 0;
                $bestWeight = null;
                for ($start = 0; $start + $size <= $lanes; $start++) {
                    $weight = 0;
                    for ($i = 0; $i < $size; $i++) {
                        $weight += $load[$start + $i];
                    }
                    if ($bestWeight === null || $weight < $bestWeight
                        || ($weight === $bestWeight && $rng->next() < 0.5)) {
                        $bestWeight = $weight;
                        $bestStart = $start;
                    }
                }
                foreach (array_values($group) as $i => $techId) {
                    $lane = $bestStart + min($i, $size - 1);
                    $assigned[$lane][] = $techId;
                    $load[$lane]++;
                }
            }

            for ($lane = 0; $lane < $lanes; $lane++) {
                foreach ($rng->shuffle($assigned[$lane]) as $row => $techId) {
                    $gi = $firstColOfEra[$era['id']] + $lane;
                    $node[$techId] = ['col' => $gi, 'lane' => $lane, 'row' => $row, 'relaxed' => false];
                    $columns[$gi]['nodes'][] = $techId;
                }
            }
        }

        // авторские связи каталога — только между технологиями этого дерева
        $links = [];
        $incoming = [];
        $outDeg = [];
        foreach ($node as $techId => $_) {
            $outDeg[$techId] = 0;
            $incoming[$techId] = [];
        }
        foreach ($prereqs as $techId => $sources) {
            if (!isset($node[$techId])) {
                continue;
            }
            foreach ($sources as $srcId) {
                if (!isset($node[$srcId]) || $node[$srcId]['col'] >= $node[$techId]['col']) {
                    continue;   // связь обязана идти строго слева направо
                }
                $links[] = ['from' => $srcId, 'to' => $techId, 'origin' => 'standard'];
                $incoming[$techId][] = $srcId;
                $outDeg[$srcId]++;
            }
        }

        // достраиваем связи, которых требует правило столбцов
        foreach ($columns as $gi => $column) {
            if ($gi === 0) {
                continue;       // корни дерева
            }
            $prev = $columns[$gi - 1]['nodes'];
            $isEraFirst = $column['lane'] === 0;

            foreach ($column['nodes'] as $techId) {
                $used = $incoming[$techId];
                $hasPrev = false;
                foreach ($used as $srcId) {
                    if ($node[$srcId]['col'] === $gi - 1) {
                        $hasPrev = true;
                        break;
                    }
                }

                if (!$hasPrev) {
                    $relax = $isEraFirst && $rng->next() > self::P_PREV_ERA;
                    if ($relax) {
                        $node[$techId]['relaxed'] = true;
                        if ($used === []) {
                            // зависимостей нет вовсе — тянем связь в более ранний столбец
                            $far = $this->pickEarlierColumn($columns, $gi, $rng);
                            $src = $this->pickSource($far, $byId[$techId], $outDeg, $used, $rng);
                            if ($src !== null) {
                                $links[] = ['from' => $src, 'to' => $techId, 'origin' => 'generated'];
                                $incoming[$techId][] = $src;
                                $used[] = $src;
                                $outDeg[$src]++;
                            }
                        }
                    } else {
                        $src = $this->pickSource($prev, $byId[$techId], $outDeg, $used, $rng);
                        if ($src !== null) {
                            $links[] = ['from' => $src, 'to' => $techId, 'origin' => 'generated'];
                            $incoming[$techId][] = $src;
                            $used[] = $src;
                            $outDeg[$src]++;
                            $hasPrev = true;
                        }
                    }
                }

                if ($hasPrev && $rng->next() < self::P_SECOND) {
                    $extra = $this->pickSource($prev, $byId[$techId], $outDeg, $used, $rng);
                    if ($extra !== null) {
                        $links[] = ['from' => $extra, 'to' => $techId, 'origin' => 'generated'];
                        $incoming[$techId][] = $extra;
                        $outDeg[$extra]++;
                    }
                }
            }
        }

        $this->orderColumns($columns, $node, $incoming);

        $nodes = [];
        foreach ($node as $techId => $n) {
            $nodes[$techId] = [
                'era_id' => $columns[$n['col']]['era_id'],
                'lane' => $n['lane'],
                'row' => $n['row'],
                'global_column' => $n['col'],
                'relaxed' => $n['relaxed'],
            ];
        }

        return ['nodes' => $nodes, 'links' => $links];
    }

    /**
     * Число столбцов эпохи: минимум два, каждый следующий с падающей
     * вероятностью и не больше, чем позволяет число карточек.
     */
    public function rollLanes(int $nodeCount, Rng $rng, int $minLanes = self::LANE_MIN): int
    {
        $cap = $nodeCount <= 1
            ? 1
            : min(self::LANE_MAX, max(self::LANE_MIN, intdiv($nodeCount, 2)));
        // столбцов не может быть меньше, чем технологий одной категории
        // в этой эпохе: иначе две из них встанут в один столбец
        $cap = max($cap, min(self::LANE_MAX, $minLanes));
        $lanes = min(max(self::LANE_MIN, $minLanes), $cap);
        $p = self::LANE_P0;
        while ($lanes < $cap && $rng->next() < $p) {
            $lanes++;
            $p *= self::LANE_DECAY;
        }

        return $lanes;
    }

    /**
     * Основа в соседнем столбце: сначала своя категория, среди равных —
     * та, от которой пока меньше всего зависит, чтобы связи расходились
     * веером, а не сходились в один узел.
     */
    private function pickSource(array $pool, array $tech, array $outDeg, array $exclude, Rng $rng): ?int
    {
        $free = [];
        foreach ($pool as $candidateId) {
            if (!in_array($candidateId, $exclude, true)) {
                $free[] = $candidateId;
            }
        }
        if ($free === []) {
            return null;
        }

        $sameBranch = [];
        foreach ($free as $candidateId) {
            if (($this->branchOf[$candidateId] ?? null) === $tech['branch_id']) {
                $sameBranch[] = $candidateId;
            }
        }
        $from = $sameBranch !== [] ? $sameBranch : $free;

        $best = PHP_INT_MAX;
        $bucket = [];
        foreach ($from as $candidateId) {
            $d = $outDeg[$candidateId] ?? 0;
            if ($d < $best) {
                $best = $d;
                $bucket = [$candidateId];
            } elseif ($d === $best) {
                $bucket[] = $candidateId;
            }
        }

        return $rng->pick($bucket);
    }

    /** Столбец левее заданного, вес падает по мере удаления. */
    private function pickEarlierColumn(array $columns, int $beforeGi, Rng $rng): array
    {
        $gi = $beforeGi - 1;
        while ($gi > 0 && $rng->next() < 0.45) {
            $gi--;
        }

        return $columns[max(0, $gi)]['nodes'];
    }

    /**
     * Порядок карточек внутри столбца по методу барицентров: карточка встаёт
     * напротив своих зависимостей, связи почти не пересекаются.
     */
    private function orderColumns(array &$columns, array &$node, array $incoming): void
    {
        $outgoing = [];
        foreach ($incoming as $techId => $sources) {
            foreach ($sources as $srcId) {
                $outgoing[$srcId][] = $techId;
            }
        }

        $posOf = function (int $techId) use (&$node, &$columns): float {
            $n = $node[$techId];
            $count = max(1, count($columns[$n['col']]['nodes']));

            return ($n['row'] + 0.5) / $count;
        };

        $sweep = function (bool $forward) use (&$columns, &$node, $incoming, $outgoing, $posOf): void {
            $order = array_keys($columns);
            if (!$forward) {
                $order = array_reverse($order);
            }
            foreach ($order as $gi) {
                if ($forward && $gi === 0) {
                    continue;
                }
                if (!$forward && $gi === count($columns) - 1) {
                    continue;
                }
                $bary = [];
                foreach ($columns[$gi]['nodes'] as $techId) {
                    $refs = $forward ? ($incoming[$techId] ?? []) : ($outgoing[$techId] ?? []);
                    if ($refs === []) {
                        $bary[$techId] = $posOf($techId);
                        continue;
                    }
                    $sum = 0.0;
                    foreach ($refs as $refId) {
                        $sum += $posOf($refId);
                    }
                    $bary[$techId] = $sum / count($refs);
                }
                usort($columns[$gi]['nodes'], static function ($a, $b) use ($bary) {
                    return $bary[$a] <=> $bary[$b];
                });
                foreach ($columns[$gi]['nodes'] as $row => $techId) {
                    $node[$techId]['row'] = $row;
                }
            }
        };

        for ($i = 0; $i < 4; $i++) {
            $sweep(true);
            $sweep(false);
        }
        $sweep(true);
    }

    /** @var array<int,int> tech_id => branch_id; нужна pickSource(), заполняется в generate() */
    private $branchOf = [];
}
