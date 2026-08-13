<?php
declare(strict_types=1);

namespace Civi;

/**
 * Детерминированный генератор псевдослучайных чисел (mulberry32).
 *
 * Нужен, чтобы одно и то же семя всегда давало одну и ту же раскладку:
 * версию можно найти по коду семени и пересобрать заново. Обычный rand()
 * для этого не годится — он зависит от версии PHP и платформы.
 */
final class Rng
{
    /** @var int 32-битное состояние */
    private $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
    }

    /** Число с плавающей точкой в диапазоне [0, 1). */
    public function next(): float
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = $this->imul($t ^ ($t >> 15), $t | 1) & 0xFFFFFFFF;
        $t ^= ($t + $this->imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;
        $t &= 0xFFFFFFFF;

        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }

    /** Целое в диапазоне [0, $bound). */
    public function nextInt(int $bound): int
    {
        if ($bound <= 1) {
            return 0;
        }

        return (int) floor($this->next() * $bound);
    }

    /** Случайный элемент массива. */
    public function pick(array $items)
    {
        if ($items === []) {
            return null;
        }

        return $items[$this->nextInt(count($items))];
    }

    /** Перемешивание Фишера — Йетса, копия массива. */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->nextInt($i + 1);
            $tmp = $items[$i];
            $items[$i] = $items[$j];
            $items[$j] = $tmp;
        }

        return $items;
    }

    /**
     * Умножение по модулю 2^32 через 16-битные половинки: прямое $a * $b
     * на больших значениях уходит в float и теряет младшие биты.
     */
    private function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;

        return (($al * $bl) + (((($ah * $bl) + ($al * $bh)) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
