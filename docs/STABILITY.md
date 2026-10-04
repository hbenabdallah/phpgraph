# What phpgraph keeps stable

phpgraph follows [semantic versioning](https://semver.org) from 1.0.0. Agents, CI scripts and tools built on it can
rely on what this page lists: it changes only in a major version (2.0.0), announced in the [changelog](../CHANGELOG.md).

## Stable

### Commands and their options

| Command | Arguments and options |
|---|---|
| `build [path]` | `-o, --output`, `-e, --exclude` (repeatable), `--depth`, `--no-vendor`, `--no-cache` |
| `serve [path]` | `-o, --output`, `-g, --graph`, `-e, --exclude`, `--no-vendor` |
| `overview` | `-g, --graph` |
| `query <question>` | `--depth`, `--budget`, `-g, --graph` |
| `explain <name>` | `-d, --direction` (`in`, `out`, `both`), `-g, --graph` |
| `path <from> <to>` | `-g, --graph` |
| `impact <name>` | `-d, --depth`, `-l, --limit`, `-a, --all`, `-s, --section` (`direct`, `routes`, `state`, `tests`, `helpers`), `-g, --graph` |
| `check [path]` | `-o, --output`, `-b, --baseline`, `--generate-baseline` |
| `mcp-config [agent]` | agent `sherpa`, `claude`, `codex`, `cursor` or `json`; `-p, --project`, `--docker` |

Exit codes: `0` on success; `1` when the command fails (project not found, an empty graph of a directory holding PHP
files, a new layer violation for `check`); `2` for invalid input (an unknown direction or agent).

### MCP tools and their parameters

| Tool | Parameters |
|---|---|
| `overview` | none |
| `query_graph` | `question` (required), `depth`, `limit` |
| `get_node` | `name` (required) |
| `get_neighbors` | `name` (required), `direction` (`in`, `out`, `both`) |
| `impact_of` | `name` (required), `depth`, `limit` (0 for complete lists), `section` |
| `shortest_path` | `from`, `to` (required) |
| `god_nodes` | `limit` |

The server speaks the MCP protocol versions 2024-11-05, 2025-03-26, 2025-06-18 and 2025-11-25, over stdio. Only
JSON-RPC goes to stdout; progress and errors go to stderr.

### The graph file, `graph.json`

Format 2: `{"version": 2, "meta": {...}, "nodes": [...], "edges": [...]}`.

- A node: `id`, `label`, `kind`, `file` (relative path or null), `line` (or null), and `service` in a multi-service
  repository.
- Node kinds: `file`, `class`, `interface`, `trait`, `enum`, `method`, `function`, `external`, `channel`, `route`.
- Node ids: `App\Domain\Order` for a class, `App\Domain\Order::place` for a method, `file:src/Order.php` for a file,
  `route:<routing file>#<METHODS> <path>` for a route, `channel:<name>` for a channel; prefixed with `service@` in a
  multi-service repository (`billing@App\Domain\Order`).
- An edge: `source`, `target`, `relation`, `confidence`, and `lines` (`"12,40"`, the source lines) when known.
- Relations: `defines`, `imports`, `extends`, `implements`, `uses_trait`, `has_method`, `overrides`, `instantiates`,
  `calls`, `references`, `dispatches`, `handled_by`, `contract`, `requests`, `receives`, `reads_state_of`.
- Confidence: `EXTRACTED` (read in the code or the configuration), `INFERRED` (resolved from declared types or
  the shape of the code), `AMBIGUOUS` (a guess, labelled as such).

A graph in another format is refused with a message asking to rebuild it; `serve` rebuilds it by itself, and also
rebuilds a graph built by another version of phpgraph.

### Files and variables

- `phpgraph.yaml` at the project root: the key `services` (a list of service directories). Unknown keys are refused.
- `phpgraph-baseline.json`, written by `check --generate-baseline`: `{"layerViolations": [...]}`.
- `phpgraph-out/`: `graph.json`, `GRAPH_REPORT.md` and the extraction cache `cache.bin`.
- Environment: `PHPGRAPH_MEMORY_LIMIT` (unlimited by default), `PHPGRAPH_INSTALL_DIR` and `PHPGRAPH_VERSION` for the
  install scripts.

## What a minor version may change

Never breaking the above, a minor version (1.1.0) may:

- find more: new edges, new routes, new handlers, calls resolved that were not; an edge's confidence may rise;
- add relations, node kinds, MCP tools, parameters, command options, `phpgraph.yaml` keys, `meta` fields;
- change the text of answers (`overview`, `impact`, `query`): it is written for an agent to read, not for a program to
  parse. Programs read `graph.json`.

A patch version (1.0.1) fixes what is wrong: an edge that should not exist may disappear.

## Not covered

The PHP classes of phpgraph are not a library API: build on the commands, the MCP tools and `graph.json`. The
extraction cache (`cache.bin`) changes with every version and is rebuilt by itself.
