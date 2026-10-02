# phpgraph as a Docker image: no PHP needed on the machine.
#
#   docker run --rm -i -u "$(id -u):$(id -g)" -v "$PWD":/project ghcr.io/hbenabdallah/phpgraph serve /project
#
# Built and published by .github/workflows/release.yml for each release tag; locally: docker build -t phpgraph .

FROM composer:2 AS build
ARG PHPGRAPH_VERSION=dev
WORKDIR /src
COPY . .
RUN PHPGRAPH_VERSION="$PHPGRAPH_VERSION" php -d phar.readonly=0 tools/build-phar.php

FROM php:8.3-cli-alpine
ARG PHPGRAPH_VERSION=dev
LABEL org.opencontainers.image.title="phpgraph" \
      org.opencontainers.image.description="Knowledge graph of a PHP project, for AI agents over MCP" \
      org.opencontainers.image.source="https://github.com/hbenabdallah/phpgraph" \
      org.opencontainers.image.licenses="LicenseRef-PolyForm-Shield-1.0.0" \
      org.opencontainers.image.version="$PHPGRAPH_VERSION"
COPY --from=build /src/build/phpgraph.phar /usr/local/bin/phpgraph
WORKDIR /project
ENTRYPOINT ["phpgraph"]
CMD ["serve", "/project"]
