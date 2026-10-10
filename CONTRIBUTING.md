# Contributing

Contributions are welcome: issues, fixes, new detectors, corpus projects.

## Before a pull request

- `composer check` must pass (php-cs-fixer, PHPStan level max, PHPUnit). Without PHP installed: `bin/dev composer check`.
- A change of the analysis is judged on the measurement corpus: run `composer corpus:measure` before and after, and give the numbers in the pull request.
- One test per new construct or detector, next to the existing ones.

## Measurement corpus

The corpus replaces a target project: real open-source projects pinned to a commit in `corpus/projects.json`. Every change of the analysis is judged on their numbers.

```bash
composer corpus:fetch                          # clone the projects into corpus/checkouts and install their dependencies
composer corpus:measure                        # measure, compare with corpus/baseline.json, write corpus/RESULTS.md
php tools/corpus.php measure --baseline [name] # update the baseline after an intended improvement
```

Dependencies are installed without scripts nor plugins: no code of these projects is run. A project without `composer.lock` is resolved once and its lock kept in `corpus/locks/`, so measurements stay reproducible. The main indicator is the share of method calls with an unknown receiver in application code.

## Releasing

Push a version tag: `git tag v0.2.0 && git push origin v0.2.0`. The release workflow runs `composer check`, builds the PHAR with that version, attaches it to a GitHub release with its SHA-256 checksum, and publishes the image `ghcr.io/hbenabdallah/phpgraph` (amd64 and arm64, tags `0.2.0`, `0.2` and `latest`). Packagist follows the tags by itself.

Downloads are counted on the Packagist package page (Composer installs), on the GitHub release page (PHAR) and on the GitHub Container Registry package page (Docker image).

## License of contributions

phpgraph is distributed under the PolyForm Shield License 1.0.0 (see `LICENSE`): free to use, modify and redistribute for any purpose that does not compete with phpgraph.

By submitting a contribution (code, documentation, tests or other material) to this repository, you agree that:

1. you wrote it, or you have the right to submit it under these terms;
2. you grant Houssem Eddine BENABDALLAH, the maintainer of phpgraph, and anyone the maintainer licenses phpgraph to, a perpetual, worldwide, non-exclusive, royalty-free, irrevocable license to use, reproduce, modify, distribute and sublicense your contribution, under the license of phpgraph or any other license the maintainer chooses;
3. you keep the copyright of your contribution.

If you cannot agree to these terms, open an issue to discuss your change instead of a pull request.
