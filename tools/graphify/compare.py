"""Compares the method-to-method calls of a phpgraph graph and a graphify graph of the same PHP project.

A method is its file and its lower-cased name: phpgraph gives both (Class::method, the file of the class), graphify
its file and its label (`.method()`). A call is a caller and a callee, both methods of the project. graphify keeps one
edge per pair with its first line only, so pairs are compared, not call sites.

    python3 compare.py phpgraph-out/graph.json graphify-out/graph.json differences.json

Prints the counts; the differences file lists each pair found by one tool only, and the pairs graphify links to
another method of the same name than phpgraph, to check samples of them in the code.
"""
import collections
import json
import re
import sys


def area(file):
    return file.split('/')[0] if file.split('/')[0] in ('src', 'tests') else 'other'


def phpgraph_calls(graph):
    methods = {
        node['id']: (node['file'], node['id'].split('::')[-1].lower())
        for node in graph['nodes']
        if node.get('kind') == 'method' and node.get('file')
    }
    calls = {}
    for edge in graph['edges']:
        if edge['relation'] == 'calls' and edge['source'] in methods and edge['target'] in methods:
            lines = [int(line) for line in str(edge.get('lines') or '').split(',') if line]
            calls[(methods[edge['source']], methods[edge['target']])] = (edge['confidence'], lines, edge['source'], edge['target'])
    return calls


def graphify_calls(graph):
    methods = {}
    for node in graph['nodes']:
        match = re.fullmatch(r'\.([A-Za-z_]\w*)\(\)', node.get('label', ''))
        if match and node.get('source_file', '').endswith('.php'):
            methods[node['id']] = (node['source_file'], match.group(1).lower())
    calls = {}
    for edge in graph['links']:
        if edge['relation'] == 'calls' and edge['source'] in methods and edge['target'] in methods:
            line = int(edge.get('source_location', 'L0')[1:] or 0)
            pair = (methods[edge['source']], methods[edge['target']])
            calls.setdefault(pair, (edge['confidence'], [], edge['source'], edge['target']))[1].append(line)
    return calls


def main(phpgraph_file, graphify_file, output):
    found = {'phpgraph': phpgraph_calls(json.load(open(phpgraph_file))), 'graphify': graphify_calls(json.load(open(graphify_file)))}
    report = {}
    for tool, calls in found.items():
        counts = collections.Counter((area(caller[0]), confidence) for (caller, _), (confidence, *_rest) in calls.items())
        report[tool] = {'pairs': len(calls), 'by area and confidence': {f'{a} {c}': n for (a, c), n in sorted(counts.items())}}

    phpgraph, graphify = found['phpgraph'], found['graphify']
    only_phpgraph = sorted(phpgraph.keys() - graphify.keys())
    only_graphify = sorted(graphify.keys() - phpgraph.keys())
    # The same caller calling a method of that name, linked by phpgraph to another one: the two disagree.
    linked = collections.defaultdict(set)
    for caller, callee in phpgraph:
        linked[(caller, callee[1])].add(callee)
    disagreeing = [pair for pair in only_graphify if linked.get((pair[0], pair[1][1]))]
    report['pairs found by both'] = len(phpgraph.keys() & graphify.keys())
    report['pairs found by phpgraph only'] = len(only_phpgraph)
    report['pairs found by graphify only'] = len(only_graphify)
    report['of which phpgraph links the call to another method of that name'] = len(disagreeing)
    print(json.dumps(report, indent=1))

    json.dump({
        'only_phpgraph': [[*caller, *callee, phpgraph[(caller, callee)][0], phpgraph[(caller, callee)][1]] for caller, callee in only_phpgraph],
        'only_graphify': [[*caller, *callee, graphify[(caller, callee)][0], graphify[(caller, callee)][1]] for caller, callee in only_graphify],
        'disagreeing': [[*caller, *callee, graphify[(caller, callee)][1], sorted(map(list, linked[(caller, callee[1])]))] for caller, callee in disagreeing],
    }, open(output, 'w'), indent=1)


if __name__ == '__main__':
    main(*sys.argv[1:4])
