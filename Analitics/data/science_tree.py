# -*- coding: utf-8 -*-
"""Научное дерево целиком: эпоха -> список технологий.

Списки лежат по файлам в science/, по три эпохи в файле.
"""

from _loader import load

p1 = load("science", "part1_ancient")
p2 = load("science", "part2_medieval")
p3 = load("science", "part3_early_modern")
p4 = load("science", "part4_industrial")
p5 = load("science", "part5_contemporary")

TREE = {
    "stone":          p1.STONE,
    "bronze":         p1.BRONZE,
    "antiquity":      p1.ANTIQUITY,
    "early_medieval": p2.EARLY_MEDIEVAL,
    "high_medieval":  p2.HIGH_MEDIEVAL,
    "exploration":    p2.EXPLORATION,
    "renaissance":    p3.RENAISSANCE,
    "enlightenment":  p3.ENLIGHTENMENT,
    "industrial_rev": p3.INDUSTRIAL_REV,
    "industrial":     p4.INDUSTRIAL,
    "modern":         p4.MODERN,
    "atomic":         p4.ATOMIC,
    "information":    p5.INFORMATION,
    "digital":        p5.DIGITAL,
    "future":         p5.FUTURE,
}
