# -*- coding: utf-8 -*-
"""Сборка статической страницы деревьев развития.

Скрипт ничего не генерирует случайно: раскладка целиком выводится из данных.
Столбец («колонка») технологии внутри эпохи равен длине самой длинной цепочки
зависимостей этой технологии внутри своей же эпохи. Поэтому число колонок в
эпохе — не настройка, а следствие того, насколько глубоко внутри эпохи одни
изобретения опираются на другие.

Запуск:  python3 Analitics/build/build.py
Результат: Analitics/index.html
"""

import html
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, os.path.join(ROOT, "data"))

from schema import (ERAS, ERA_ORDER, ERA_NAME, ERA_SPAN,
                    SCIENCE_CATS, CULTURE_CATS)          # noqa: E402
import science_tree                                       # noqa: E402
import culture_tree                                       # noqa: E402


class BuildError(Exception):
    pass


def collect(tree_module, tree_code):
    """Разворачивает поэпохальные списки в плоский список записей."""
    out = []
    seen = set()
    for era_code, _, _ in ERAS:
        for tech in tree_module.TREE.get(era_code, []):
            if tech.code in seen:
                raise BuildError("повтор кода %s в дереве %s" % (tech.code, tree_code))
            seen.add(tech.code)
            out.append({
                "code": tech.code, "name": tech.name, "cat": tech.cat,
                "desc": tech.desc, "note": tech.note, "pre": list(tech.pre),
                "era": era_code, "tree": tree_code,
            })
    return out


def validate(items, cats, tree_code):
    """Проверки, без которых дерево нельзя показывать."""
    by_code = {i["code"]: i for i in items}
    cat_codes = {c[0] for c in cats}
    problems = []

    for it in items:
        if it["cat"] not in cat_codes:
            problems.append("%s: неизвестная категория %s" % (it["code"], it["cat"]))
        if not it["desc"] or not it["note"]:
            problems.append("%s: пустое описание или справка" % it["code"])
        for p in it["pre"]:
            if p not in by_code:
                problems.append("%s: основание %s не найдено в дереве %s"
                                % (it["code"], p, tree_code))
                continue
            if ERA_ORDER[by_code[p]["era"]] > ERA_ORDER[it["era"]]:
                problems.append("%s (%s) опирается на более позднюю %s (%s)"
                                % (it["code"], it["era"], p, by_code[p]["era"]))

    # ровно один корень на дерево: всё остальное на что-то опирается
    roots = [i["code"] for i in items if not i["pre"]]
    if len(roots) > 1:
        problems.append("технологии без оснований (кроме корня): %s" % ", ".join(roots[1:]))
    if not roots:
        problems.append("нет ни одной технологии без оснований — вероятен цикл")

    return problems


def lanes(items):
    """Колонка внутри эпохи = глубина зависимостей внутри своей эпохи.

    Возвращает {code: lane} и {era: число колонок}. Цикл внутри эпохи
    обнаруживается тем, что глубину не удаётся досчитать.
    """
    by_code = {i["code"]: i for i in items}
    lane = {}
    for era_code, _, _ in ERAS:
        in_era = [i for i in items if i["era"] == era_code]
        pending = {i["code"] for i in in_era}
        guard = 0
        while pending:
            guard += 1
            if guard > len(in_era) + 5:
                raise BuildError("цикл зависимостей внутри эпохи %s: %s"
                                 % (era_code, sorted(pending)))
            ready = []
            for code in pending:
                same_era = [p for p in by_code[code]["pre"]
                            if p in by_code and by_code[p]["era"] == era_code]
                if all(p in lane for p in same_era):
                    ready.append(code)
            for code in ready:
                same_era = [p for p in by_code[code]["pre"]
                            if p in by_code and by_code[p]["era"] == era_code]
                lane[code] = 0 if not same_era else max(lane[p] for p in same_era) + 1
                pending.discard(code)

    counts = {}
    for era_code, _, _ in ERAS:
        in_era = [i["code"] for i in items if i["era"] == era_code]
        counts[era_code] = (max(lane[c] for c in in_era) + 1) if in_era else 0
    return lane, counts


def rows(items, lane):
    """Порядок карточек внутри колонки: метод барицентров.

    Технология встаёт напротив середины своих оснований, что заметно
    распрямляет линии связей и уменьшает число пересечений.
    """
    by_code = {i["code"]: i for i in items}
    order = {}
    for era_code, _, _ in ERAS:
        in_era = [i for i in items if i["era"] == era_code]
        max_lane = max([lane[i["code"]] for i in in_era], default=-1)
        for ln in range(max_lane + 1):
            col = [i for i in in_era if lane[i["code"]] == ln]

            def key(it):
                anchors = [order[p] for p in it["pre"] if p in order]
                bary = sum(anchors) / len(anchors) if anchors else 999.0
                return (bary, it["cat"], it["name"])

            for row, it in enumerate(sorted(col, key=key)):
                order[it["code"]] = row
    return order


def build_tree(module, tree_code, cats):
    items = collect(module, tree_code)
    problems = validate(items, cats, tree_code)
    if problems:
        raise BuildError("дерево %s:\n  - %s" % (tree_code, "\n  - ".join(problems)))
    lane, counts = lanes(items)
    order = rows(items, lane)

    colors = {c[0]: c[2] for c in cats}
    names = {c[0]: c[1] for c in cats}

    global_col = {}
    running = 0
    for era_code, _, _ in ERAS:
        for ln in range(counts[era_code]):
            global_col[(era_code, ln)] = running + ln
        running += counts[era_code]

    nodes = []
    for it in items:
        nodes.append({
            "id": it["code"], "name": it["name"], "era": it["era"],
            "lane": lane[it["code"]], "row": order[it["code"]],
            "col": global_col[(it["era"], lane[it["code"]])],
            "cat": names[it["cat"]], "color": colors[it["cat"]],
            "desc": it["desc"], "note": it["note"], "pre": it["pre"],
        })

    links = []
    for it in items:
        for p in it["pre"]:
            links.append({"from": p, "to": it["code"]})

    used_cats = []
    for code, name, color in cats:
        if any(i["cat"] == code for i in items):
            used_cats.append({"name": name, "color": color})

    eras = [{"id": c, "name": ERA_NAME[c], "span": ERA_SPAN[c],
             "lanes": counts[c],
             "count": sum(1 for i in items if i["era"] == c)}
            for c, _, _ in ERAS]

    return {"code": tree_code, "eras": eras, "nodes": nodes,
            "links": links, "cats": used_cats}, items


TEMPLATE_HEAD = """<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Деревья развития Civi — разбор</title>
<style>
%s
</style>
</head>
<body>
"""


def render(trees, css, js):
    stats = []
    for t in trees:
        stats.append("%s: %d технологий, %d связей, %d колонок"
                     % ("научное дерево" if t["code"] == "science" else "социальное дерево",
                        len(t["nodes"]), len(t["links"]),
                        sum(e["lanes"] for e in t["eras"])))

    parts = [TEMPLATE_HEAD % css]
    parts.append("""
<div class="wrap">
  <header class="page-head">
    <h1>Деревья развития Civi — пересобранный разбор</h1>
    <p class="lead">Статическая выкладка двух деревьев по эпохам. Ничего не генерируется
      случайно: номер колонки внутри эпохи равен глубине зависимостей технологии
      внутри своей же эпохи, поэтому раскладка целиком выводится из связей.</p>
    <p class="hint">%s. Наведите на карточку — подсветятся её основания и то,
      что она открывает. Щелчок открывает историческую справку.</p>
  </header>
  <div class="tabs" id="tabs"></div>
""" % (" · ".join(stats)))

    for t in trees:
        title = ("Дерево технологий — наука" if t["code"] == "science"
                 else "Дерево социальных концепций — культура")
        sub = ("Что цивилизация умеет делать: вещество, энергия, орудия, приборы, расчёт."
               if t["code"] == "science"
               else "Как цивилизация устроена: власть, право, вера, труд, слово, общность.")
        parts.append(
            '<section class="tree-board" id="board-%s" data-tree="%s">'
            '<h2 class="%s">%s</h2><p class="sub">%s</p>'
            '<div class="board-legend"></div>'
            '<div class="board-scroll"><div class="board"><svg class="edges"></svg></div></div>'
            '</section>' % (t["code"], t["code"], t["code"], html.escape(title), html.escape(sub))
        )

    parts.append('<footer class="page-foot">Материал разбора: '
                 '<code>Analitics/</code>. Данные — <code>Analitics/data/</code>, '
                 'перечень правок — <code>Analitics/ОТЧЁТ ОБ ИЗМЕНЕНИЯХ.md</code>.</footer>')
    parts.append('</div>')
    parts.append('<div class="overlay" id="overlay"><div class="sheet" id="sheet"></div></div>')
    parts.append('<script>\nvar TREES = %s;\n%s\n</script>'
                 % (json.dumps(trees, ensure_ascii=False), js))
    parts.append('</body></html>')
    return "\n".join(parts)


def main():
    science, sci_items = build_tree(science_tree, "science", SCIENCE_CATS)
    culture, cul_items = build_tree(culture_tree, "culture", CULTURE_CATS)

    with open(os.path.join(HERE, "board.css"), encoding="utf-8") as f:
        css = f.read()
    with open(os.path.join(HERE, "board.js"), encoding="utf-8") as f:
        js = f.read()

    out = render([science, culture], css, js)
    path = os.path.join(ROOT, "index.html")
    with open(path, "w", encoding="utf-8") as f:
        f.write(out)

    for tree, items in ((science, sci_items), (culture, cul_items)):
        print("%-9s %3d технологий, %3d связей, колонок по эпохам: %s"
              % (tree["code"], len(tree["nodes"]), len(tree["links"]),
                 " ".join(str(e["lanes"]) for e in tree["eras"])))
    print("записано:", path, "(%.0f КБ)" % (os.path.getsize(path) / 1024))


if __name__ == "__main__":
    try:
        main()
    except BuildError as exc:
        print("ОШИБКА СБОРКИ\n%s" % exc)
        sys.exit(1)
