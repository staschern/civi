# -*- coding: utf-8 -*-
"""Сверка пересобранных деревьев с исходным каталогом проекта.

Показывает, что изменилось по сравнению с db/catalog/technologies.txt:
переносы между эпохами и деревьями, смену категории и списки названий,
которых нет с одной из сторон.

Сверка идёт по названию, поэтому переименование выглядит как пара
«исчезло там — появилось здесь». Разбор этих пар — в файле
Analitics/ОТЧЁТ ОБ ИЗМЕНЕНИЯХ.md.

Запуск из корня репозитория:  python3 Analitics/build/diff_catalog.py
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
REPO = os.path.dirname(ROOT)
sys.path.insert(0, os.path.join(ROOT, "data"))

from schema import ERAS, ERA_NAME                      # noqa: E402
import science_tree                                     # noqa: E402
import culture_tree                                     # noqa: E402

CATALOG = os.path.join(REPO, "db", "catalog", "technologies.txt")
ERA_POS = {code: i for i, (code, _, _) in enumerate(ERAS)}


def read_catalog():
    rows = {}
    with open(CATALOG, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            era, tree, cat, name = line.split("|")
            rows[(tree, name)] = (era, cat)
    return rows


def read_new():
    rows = {}
    for tree, module in (("science", science_tree), ("culture", culture_tree)):
        for era, _, _ in ERAS:
            for tech in module.TREE.get(era, []):
                rows[(tree, tech.name)] = (era, tech.cat)
    return rows


def section(title):
    print()
    print("=== %s ===" % title)


def main():
    old, new = read_catalog(), read_new()
    both = set(old) & set(new)

    section("ПЕРЕНЕСЕНЫ МЕЖДУ ЭПОХАМИ (название сохранено)")
    for key in sorted(both, key=lambda k: (k[0], k[1])):
        if old[key][0] != new[key][0]:
            print("  %-8s %-42s %s -> %s"
                  % (key[0], key[1], ERA_NAME[old[key][0]], ERA_NAME[new[key][0]]))

    section("ИЗМЕНЕНА КАТЕГОРИЯ")
    for key in sorted(both, key=lambda k: (k[0], k[1])):
        if old[key][1] != new[key][1]:
            print("  %-8s %-42s %s -> %s" % (key[0], key[1], old[key][1], new[key][1]))

    section("ПЕРЕНЕСЕНЫ МЕЖДУ ДЕРЕВЬЯМИ")
    moved = False
    for tree, name in sorted(old):
        other = "culture" if tree == "science" else "science"
        if (other, name) in new and (tree, name) not in new:
            print("  %-42s %s -> %s" % (name, tree, other))
            moved = True
    if not moved:
        print("  нет")

    for title, gone, source in (
        ("ЕСТЬ В КАТАЛОГЕ, НЕТ В РАЗБОРЕ (переименовано, объединено или удалено)",
         set(old) - set(new), old),
        ("ЕСТЬ В РАЗБОРЕ, НЕТ В КАТАЛОГЕ (переименовано или добавлено)",
         set(new) - set(old), new),
    ):
        section(title)
        for tree in ("science", "culture"):
            items = [(source[k][0], k[1]) for k in gone if k[0] == tree]
            items.sort(key=lambda x: (ERA_POS[x[0]], x[1]))
            print("  -- %s: %d" % (tree, len(items)))
            for era, name in items:
                print("     %-24s %s" % (ERA_NAME[era], name))

    print()
    print("итого в каталоге: %d, в разборе: %d" % (len(old), len(new)))


if __name__ == "__main__":
    main()
