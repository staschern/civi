# -*- coding: utf-8 -*-
"""Загрузка файлов дерева по явному пути.

Файлы двух деревьев называются одинаково (part1_ancient.py и т. д.), поэтому
обычный import подхватил бы уже загруженный модуль другого дерева. Загрузка по
пути с уникальным именем в реестре модулей это исключает.
"""

import importlib.util
import os


def load(tree_dir, name):
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), tree_dir, name + ".py")
    spec = importlib.util.spec_from_file_location("%s_%s" % (tree_dir, name), path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module
