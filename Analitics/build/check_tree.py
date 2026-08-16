# -*- coding: utf-8 -*-
"""Проверка деревьев на логические изъяны раскладки.

Показывает три вещи:

1. Точки входа — технологии без оснований. Их должно быть несколько, и все они
   обязаны лежать в первой эпохе.
2. Тупики — технологии, на которые никто не опирается. В последней эпохе это
   нормально, в остальных — повод дать продолжение или убрать технологию.
3. Подозрительные пары внутри категории — две соседние по времени технологии
   одной категории, между которыми нет ни зависимости, ни общего основания.
   Это либо законная развилка (две ветви от разных точек входа), либо провал в
   связях; каждую пару надо смотреть глазами.

Запуск из корня репозитория:  python3 Analitics/build/check_tree.py
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, os.path.join(ROOT, "data"))

from schema import ERAS, ERA_NAME, ERA_ORDER, SCIENCE_CATS, CULTURE_CATS  # noqa: E402
import science_tree                                                        # noqa: E402
import culture_tree                                                        # noqa: E402


def collect(module):
    return [(tech, era) for era, _, _ in ERAS for tech in module.TREE.get(era, [])]


def closure(pre):
    """Все основания каждой технологии, включая косвенные."""
    anc = {code: set(parents) for code, parents in pre.items()}
    changed = True
    while changed:
        changed = False
        for code in anc:
            grown = set(anc[code])
            for parent in list(anc[code]):
                grown |= anc.get(parent, set())
            if grown != anc[code]:
                anc[code] = grown
                changed = True
    return anc


def report(label, module, cats):
    cat_name = {c[0]: c[1] for c in cats}
    items = collect(module)
    pre = {t.code: list(t.pre) for t, _ in items}
    era = {t.code: e for t, e in items}
    name = {t.code: t.name for t, _ in items}
    anc = closure(pre)

    used = set()
    for parents in pre.values():
        used.update(parents)
    dependents = {}
    for code, parents in pre.items():
        for p in parents:
            dependents.setdefault(p, []).append(code)

    first, last = ERAS[0][0], ERAS[-1][0]

    print("### %s — %d технологий" % (label, len(items)))

    roots = [t.code for t, e in items if not t.pre]
    print("  точки входа (%d):" % len(roots))
    for code in roots:
        mark = "" if era[code] == first else "  ← НЕ В ПЕРВОЙ ЭПОХЕ"
        print("    %s — %s%s" % (name[code], ERA_NAME[era[code]], mark))

    dead = [t.code for t, e in items if t.code not in used and e != last]
    print("  тупики вне последней эпохи: %d" % len(dead))
    for code in dead:
        print("    %s — %s" % (name[code], ERA_NAME[era[code]]))

    ending = [t.code for t, e in items if t.code not in used and e == last]
    print("  завершающие технологии последней эпохи: %d" % len(ending))

    by_cat = {}
    for t, _ in items:
        by_cat.setdefault(t.cat, []).append(t.code)
    suspicious = []
    for cat, codes in by_cat.items():
        codes.sort(key=lambda c: ERA_ORDER[era[c]])
        for a, b in zip(codes, codes[1:]):
            if a in anc[b] or b in anc[a]:
                continue
            if anc[a] & anc[b]:
                continue
            suspicious.append((cat_name[cat], a, b))
    print("  пары без зависимости и без общего основания: %d" % len(suspicious))
    for cat, a, b in suspicious:
        print("    [%s] %s (%s) и %s (%s)"
              % (cat, name[a], ERA_NAME[era[a]], name[b], ERA_NAME[era[b]]))
    print()


def main():
    report("НАУЧНОЕ ДЕРЕВО", science_tree, SCIENCE_CATS)
    report("СОЦИАЛЬНОЕ ДЕРЕВО", culture_tree, CULTURE_CATS)


if __name__ == "__main__":
    main()
