"""The files calling a method that each graph finds, for the methods whose name is declared once in the project and its
vendor/: there, the files where `->name(` or `::name(` is written outside a comment are the callers, grep is the truth. Name matching
works best on such methods, so it is the case most favourable to a tool linking calls by name.

    python3 recall.py <project> phpgraph-out/graph.json graphify-out/graph.json recall.json

Calls from the file declaring the method and files outside src/ and tests/ are left out, of the truth and of both
graphs. A file a graph finds that grep does not is counted as false.
"""
import collections
import json
import os
import re
import sys

AREAS = ('src', 'tests')


def php_files(root, base):
    for directory, _, files in os.walk(os.path.join(root, base)):
        for file in files:
            if file.endswith('.php'):
                path = os.path.join(directory, file)
                yield os.path.relpath(path, root), open(path, errors='replace').read()


def unique_methods(root):
    declared = collections.Counter()
    where = {}
    for base in (*AREAS, 'migrations', 'vendor'):
        for file, code in php_files(root, base):
            for match in re.finditer(r'function\s+&?\s*([A-Za-z_]\w*)\s*\(', code):
                name = match.group(1).lower()
                declared[name] += 1
                if file.startswith('src/'):
                    where[name] = file
    # Magic methods and short names (get, set, add...) are left out.
    return {name: file for name, file in where.items() if declared[name] == 1 and not name.startswith('__') and len(name) > 3}


def without_comments(code):
    """The code with its comments removed, strings kept: `glob($path . '/*.twig')` opens no comment."""
    kept = []
    i = 0
    while i < len(code):
        if code[i] in '\'"':
            end = i + 1
            while end < len(code) and code[end] != code[i]:
                end += 2 if code[end] == '\\' else 1
            kept.append(code[i:end + 1])
            i = end + 1
        elif code.startswith('/*', i):
            end = code.find('*/', i + 2)
            i = len(code) if end < 0 else end + 2
        elif code.startswith('//', i) or (code[i] == '#' and not code.startswith('#[', i)):
            end = code.find('\n', i)
            i = len(code) if end < 0 else end
        else:
            kept.append(code[i])
            i += 1
    return ''.join(kept)


def callers_by_grep(root, methods):
    callers = collections.defaultdict(set)
    for base in AREAS:
        for file, code in php_files(root, base):
            for match in re.finditer(r'(?:->|::)\s*([A-Za-z_]\w*)\s*\(', without_comments(code)):
                name = match.group(1).lower()
                if name in methods and file != methods[name]:
                    callers[name].add(file)
    return callers


def kept(file, name, methods):
    return file is not None and file.split('/')[0] in AREAS and file != methods[name]


def phpgraph_callers(graph, methods):
    nodes = {node['id']: node for node in graph['nodes']}
    callers = collections.defaultdict(set)
    for edge in graph['edges']:
        target = nodes.get(edge['target'], {})
        if edge['relation'] != 'calls' or target.get('kind') != 'method':
            continue
        name = edge['target'].split('::')[-1].lower()
        caller = nodes.get(edge['source'], {}).get('file')
        if name in methods and target.get('file') == methods[name] and kept(caller, name, methods):
            callers[name].add(caller)
    return callers


def graphify_callers(graph, methods):
    nodes = {node['id']: node for node in graph['nodes']}
    callers = collections.defaultdict(set)
    for edge in graph['links']:
        if edge['relation'] != 'calls':
            continue
        target = nodes[edge['target']]
        match = re.fullmatch(r'\.(\w+)\(\)', target.get('label', ''))
        if not match:
            continue
        name = match.group(1).lower()
        if name in methods and target.get('source_file') == methods[name] and kept(edge['source_file'], name, methods):
            callers[name].add(edge['source_file'])
    return callers


def main(root, phpgraph_file, graphify_file, output):
    methods = unique_methods(root)
    truth = callers_by_grep(root, methods)
    total = sum(len(files) for files in truth.values())
    print(f'{len(truth)} methods declared once and called from another file: {total} caller files')
    found = {
        'phpgraph': phpgraph_callers(json.load(open(phpgraph_file)), methods),
        'graphify': graphify_callers(json.load(open(graphify_file)), methods),
    }
    for tool, callers in found.items():
        hits = sum(len(callers[name] & truth[name]) for name in truth)
        false = sum(len(callers[name] - truth.get(name, set())) for name in callers)
        print(f'{tool}: {hits} of {total} found ({100 * hits / total:.2f} %), {false} false')
    json.dump({
        name: {'grep': sorted(truth[name]), **{tool: sorted(callers[name]) for tool, callers in found.items()}}
        for name in sorted(truth)
    }, open(output, 'w'), indent=1)


if __name__ == '__main__':
    main(*sys.argv[1:5])
