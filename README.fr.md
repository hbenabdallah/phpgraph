<p align="center">
  <img src="https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/docs/phpgraph.png" alt="phpgraph transforme une base de code PHP en graphe de connaissances (domaines, handlers, repositories, contrôleurs, adapters), servi aux agents IA par une CLI et un serveur MCP" width="100%">
</p>

<h1 align="center">phpgraph</h1>

<p align="center">
  <b>Le graphe de connaissances de votre code PHP, pour les agents IA.</b><br>
  Analyse statique servie par MCP à <a href="https://github.com/hbenabdallah/sherpa">Sherpa</a>, Claude Code, Codex, Cursor et à tout client MCP.<br>
  Pas de LLM, pas de réseau, pas d'embeddings : des faits déterministes, chacun avec un niveau de confiance.
</p>

<p align="center">
  <a href="https://github.com/hbenabdallah/phpgraph/actions/workflows/ci.yml"><img src="https://github.com/hbenabdallah/phpgraph/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/hbenabdallah/phpgraph"><img src="https://img.shields.io/packagist/v/hbenabdallah/phpgraph" alt="Version Packagist"></a>
  <a href="https://packagist.org/packages/hbenabdallah/phpgraph"><img src="https://img.shields.io/packagist/dt/hbenabdallah/phpgraph" alt="Téléchargements"></a>
  <img src="https://img.shields.io/badge/php-%3E%3D8.2-777bb4" alt="PHP 8.2+">
  <a href="https://github.com/hbenabdallah/phpgraph/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-PolyForm%20Shield%201.0.0-blue" alt="Licence : PolyForm Shield 1.0.0"></a>
</p>

<p align="center"><a href="https://github.com/hbenabdallah/phpgraph/blob/main/README.md">English</a> · <b>Français</b></p>

---

## Pourquoi phpgraph ?

Un agent IA lâché dans un projet PHP qu'il ne connaît pas lit les fichiers un par un et cherche des noms. Il passe à côté de l'essentiel d'une vraie base de code : **qui appelle quoi, quel handler reçoit un message, quel contrôleur sert une route, ce qui casse si une méthode change, et quelle couche dépend de quelle autre**.

phpgraph analyse tout le projet une fois (avec [`nikic/php-parser`](https://github.com/nikic/PHP-Parser)) et répond à ces questions à partir d'un graphe :

- **Des faits déterministes, pas des devinettes.** Chaque relation est `EXTRACTED` (lue dans le code), `INFERRED` (résolue à partir des types déclarés) ou `AMBIGUOUS` (une supposition, signalée comme telle).
- **Il dit ce qu'il ne sait pas.** Appels non résolus, `vendor/` absent, messages sans handler : les trous sont signalés, et l'agent sait quand lire le code lui-même.
- **Pensé pour le DDD et l'architecture hexagonale**, puis les microservices, puis le PHP en général : Symfony, Laravel, Ecotone, PrestaShop, WordPress, ou PHP sans framework.
- **Privé par construction.** Votre code ne quitte pas votre machine, et phpgraph ne l'exécute jamais : il l'analyse seulement.

## Fonctionnalités

- **Graphe d'appels avec inférence de types** : types de retour, appels enchaînés (`$a->b()->c()`), variables locales, docblocks (`@return`, `@var`), et signatures des dépendances de `vendor/`, pour que les chaînes traversent les API de Doctrine, Symfony ou Laravel.
- **Architecture** : couches DDD et hexagonales, bounded contexts, règles de couches vérifiées en CI (`phpgraph check`) et analyse d'impact (`impact_of`) : ce qui dépend d'une classe ou d'une méthode, et les tests à lancer.
- **Messages et événements** : envoi → message → handler pour Symfony Messenger, les jobs et événements Laravel, Ecotone, le CQRS de PrestaShop, les hooks WordPress, les événements de domaine et vos propres bus, à partir des attributs, de la configuration des services (YAML, XML, PHP) ou de la forme du code.
- **HTTP** : routes (attributs Symfony, fichiers de routage YAML et PHP, ressources API Platform, fichiers de routes Laravel, contrôleurs nommés par identifiant de service) reliées à leurs contrôleurs, et appels HTTP reliés aux routes qu'ils atteignent. Une route dont la classe contrôleur n'existe ni dans le projet ni dans `vendor/` est signalée avec son fichier de routage.
- **Injection de dépendances** : ce que la configuration du conteneur Symfony injecte au-delà des types du constructeur, tous les services d'un tag (`tagged_iterator`, `#[AutowireIterator]`) ou un service nommé par son identifiant, en PHP, YAML ou XML, y compris dans des helpers appelés avec des arguments littéraux (`Wiring::wire($services, 'sales', ...)`, évalués, jamais exécutés) : un validateur est relié aux règles qu'il reçoit.
- **Microservices** : un espace d'identifiants par service, et des liens entre services par leurs contrats : classes de messages partagées, clés de routage, routes HTTP.
- **Serveur MCP sans configuration** : construit au premier usage, reconstruit de façon incrémentale quand le code change (environ 3 s par modification sur un projet de 8 400 fichiers).

## Démarrage rapide

**1. Installer.** Un seul exécutable, sans PHP (Linux et macOS en x86_64 et ARM, Windows en x86_64) :

```bash
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh                  # dernière version
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh -s -- 0.2.0     # une version donnée
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh -s -- dev-main  # la branche main
```

```powershell
irm https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.ps1 | iex                       # Windows
$env:PHPGRAPH_VERSION = "dev-main"; irm https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.ps1 | iex
```

Le script télécharge le binaire, vérifie son SHA-256 et le place dans `~/.local/bin` (Windows : `%LOCALAPPDATA%\Programs\phpgraph`, ajouté au PATH) ; `PHPGRAPH_INSTALL_DIR` change le dossier. Le relancer met à jour. `dev-main` est reconstruit à chaque push sur `main` (la pré-version `edge`). Le binaire est un runtime PHP statique qui contient phpgraph, construit avec [static-php-cli](https://github.com/crazywhalecc/static-php-cli).

Avec PHP 8.2 ou plus, ou avec Docker :

```bash
composer require --dev hbenabdallah/phpgraph       # ou : composer global require hbenabdallah/phpgraph
curl -LO https://github.com/hbenabdallah/phpgraph/releases/latest/download/phpgraph.phar
docker pull ghcr.io/hbenabdallah/phpgraph          # rien d'autre à installer
```

**2. Brancher votre agent.** `mcp-config` affiche une configuration prête à coller, avec les chemins remplis (`vendor/bin/phpgraph` après une installation par Composer) :

```bash
phpgraph mcp-config sherpa    # Sherpa : ~/.config/sherpa/mcp.json, une déclaration pour tous les projets
phpgraph mcp-config claude    # Claude Code : une commande `claude mcp add`, ou .mcp.json
phpgraph mcp-config codex     # Codex : ~/.codex/config.toml
phpgraph mcp-config cursor    # Cursor : .cursor/mcp.json, à partager avec l'équipe
phpgraph mcp-config json      # tout autre client MCP sur stdio
```

Avec [Sherpa](https://github.com/hbenabdallah/sherpa), un agent de code pour le terminal, une seule déclaration dans `~/.config/sherpa/mcp.json` sert tous les projets : Sherpa lance ses serveurs MCP depuis le projet où il tourne.

```json
{
  "mcpServers": {
    "phpgraph": { "command": "/home/vous/.local/bin/phpgraph", "args": ["serve"] }
  }
}
```

Avec Claude Code (chemins absolus, comme les affiche `mcp-config`) :

```bash
claude mcp add phpgraph -- /home/vous/.local/bin/phpgraph serve /chemin/du/projet
```

**3. Interroger votre agent**, par exemple : *« Donne-moi une vue d'ensemble de ce projet »*, *« Comment une commande est-elle passée ? »* ou *« Qu'est-ce qui casse si je modifie `OrderRepository::save` ? »*. Le graphe est construit au premier appel, puis tenu à jour.

## Outils MCP

| Outil | Pour |
|---|---|
| `overview` | Commencer ici : stack Composer, arbre des namespaces avec les couches, bounded contexts, violations des règles de couches, messages, routes HTTP (chacune avec méthode, chemin, contrôleur et fichier jusqu'à 30, sinon regroupées par préfixe de chemin), services, et ce que le graphe ne voit pas. |
| `query_graph` | Trouver le code d'un sujet sans connaître les noms de classes, par les mots de leurs noms (validated trouve `Validator`), avec les classes de la fonctionnalité autour de celles qu'il nomme. Une question sur les routes (*« mooc courses routes »*, *« GET /courses »*) renvoie les routes correspondantes et leurs contrôleurs. Les familles de classes autour de la réponse sont comptées par dossier (*ContextRuleInterface: 34 in …*), les listes injectées une fois (*ContextValidator receives 34 ContextRuleInterface*). |
| `outline` | Avant d'expliquer une fonctionnalité : son plan structurel, assez pour ne lire que les quelques fichiers à la logique non évidente. Les classes qui la composent, par namespace et couche, avec la première phrase de leur docblock, leurs signatures publiques, constantes et cas d'enum ; les interfaces et classes de base autour avec leurs implémentations par module (0 signalé) ; les routes qui y mènent, chaîne par chaîne, à travers les closures qu'elle exécute ; le comportement lu dans les corps (throws gardés, retours anticipés, branches sur des constantes, plafonds de boucle, état comparé avant et après) ; le câblage du conteneur avec les ids de services ; ses utilisateurs, les tests comptés par module, ce qu'aucun test ne touche et ce que rien n'utilise. Environ 15 Ko ; une liste coupée nomme la section qui la donne en entier (`section behaviour`, 1 à 9 Ko). |
| `get_node` | Lire une classe, une méthode, une route ou un canal avec toutes ses relations ; les tests qui l'utilisent sont comptés par module. |
| `get_neighbors` | Voir ce qui utilise un nœud (`in`) ou ce dont il dépend (`out`). |
| `impact_of` | Avant une modification : toutes les classes qui dépendent d'une classe ou d'une méthode, les plus proches d'abord, avec les lignes des appels ; les appelants via l'interface qu'elle implémente ; le code qui dépend de l'état qu'elle écrit ; les routes qui l'atteignent, avec chaque chaîne ; et les tests à lancer, trouvés à travers les helpers de test. Il signale les classes qu'aucun test ne touche et celles que rien n'utilise dans l'application. |
| `shortest_path` | Voir comment deux morceaux de code sont reliés. |
| `god_nodes` | Trouver les nœuds dont dépend le plus de code. |

Le serveur demande à l'agent d'appeler `overview` en premier. Un chemin, tel que l'agent le voit, sur [php-ddd-example](https://github.com/CodelyTV/php-ddd-example) :

```text
Shortest path (4 hops):
  PUT /courses/{id} --handled_by--> CoursesPutController::__invoke()
    --dispatches--> CreateCourseCommand --handled_by--> CreateCourseCommandHandler::__invoke()
    --calls--> CourseCreator::__invoke()
```

## Ligne de commande

```bash
phpgraph build [chemin]                      # phpgraph-out/graph.json et GRAPH_REPORT.md
phpgraph overview                            # stack, structure et trous
phpgraph impact "OrderRepository::save"      # ce qui en dépend, et les tests à lancer (--all, --section tests, --format json)
phpgraph check                               # règles de couches, pour la CI : code 1 sur une nouvelle violation
phpgraph explain "PlaceOrderHandler"         # un nœud et ses relations (-d in|out)
phpgraph path "StockChecker" "DbalOrderRepository"
phpgraph query "comment le stock est vérifié"
phpgraph outline "notification validation"   # le plan d'une fonctionnalité (--section flow, --format json)
phpgraph serve [chemin]                      # le serveur MCP, sur stdio
phpgraph mcp-config claude|codex|cursor|json [--docker image]
```

Options de `build` : `-e` pour exclure des chemins (répétable), `--no-vendor` pour ne pas lire les dépendances, `--no-cache` pour tout analyser à nouveau. `vendor`, `node_modules`, `var`, `.git` et les fichiers ignorés par les `.gitignore` du projet lui-même ne sont pas analysés ; un dépôt git au-dessus du projet n'applique jamais ses règles, si bien qu'un projet copié dans un dossier ignoré est quand même lu. Quand le dossier contient des fichiers PHP mais qu'ils sont tous exclus, `build` échoue en le disant, et chaque réponse MCP commence par cet avertissement au lieu de servir un graphe vide. La mémoire n'est pas limitée par défaut ; `PHPGRAPH_MEMORY_LIMIT=2G` fixe une limite. Avec PHP installé, opcache et son JIT accélèrent les builds d'environ 20 % : `php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M vendor/bin/phpgraph build` (l'image Docker et les commandes affichées par `mcp-config` les utilisent déjà).

## Ce que contient le graphe

| Relation | De → vers |
|---|---|
| `defines`, `imports` | fichier → classe, instruction `use` |
| `extends`, `implements`, `uses_trait` | classe → parent, interface, trait |
| `has_method`, `overrides` | classe → méthode, méthode → la méthode qu'elle redéfinit |
| `instantiates`, `references` | `new Foo()` ; types de paramètres, de retour et de propriétés, `catch`, `instanceof`, constantes, attributs |
| `calls` | méthode → méthode résolue, avec les lignes des appels ; une méthode qui exécute une closure reçue (`$apply(...)`) appelle ce que la closure appelle, INFERRED, `via` `closure of <la méthode qui l'écrit>` |
| `dispatches`, `handled_by` | envoi → message ou canal → handler ; route → contrôleur ; classe testée par une méthode `supports()` → cette stratégie |
| `contract` | une classe de message envoyée par un service → la même classe traitée par un autre |
| `requests` | appel HTTP → la route qu'il atteint, du même service ou d'un autre |
| `receives` | service → chaque service que la configuration du conteneur lui injecte (un tag, un identifiant, le service décoré) ; `via` dit lequel : `service sales.pipeline` pour l'un des services d'une même classe |
| `reads_state_of` | méthode → une méthode de la même classe qui modifie, hors constructeur, une propriété qu'elle lit (`hasErrors()` → `add()`), INFERRED |

Chaque relation porte un niveau de confiance :

| Niveau | Signification |
|---|---|
| `EXTRACTED` | Lu dans le code d'un fichier, ou dans la configuration (attribut, tag de service, fichier de routes). |
| `INFERRED` | Résolu entre fichiers à partir des types déclarés : paramètres, propriétés, `$this`, héritage, types de retour, forme du code. |
| `AMBIGUOUS` | Une supposition, par exemple la seule méthode du projet portant ce nom, appelée sur un récepteur de type inconnu. |

<details>
<summary><b>Frameworks et conventions reconnus</b></summary>

| | Handlers et écouteurs | Messages envoyés | HTTP |
|---|---|---|---|
| **Symfony** | `#[AsMessageHandler]`, `#[AsEventListener]`, tags `messenger.message_handler` et `kernel.event_listener` (YAML, XML, PHP, `_instanceof`), `getSubscribedEvents()`, `addListener()` | `MessageBusInterface`, `EventDispatcherInterface`, événements nommés | `#[Route]` (chemins en constantes de classe compris), fichiers de routage YAML et PHP, contrôleurs nommés par identifiant de service ; `#[ApiResource]` et attributs d'opération d'API Platform, traités par leur provider, processor ou contrôleur |
| **Laravel** | `$listen`, `Event::listen()`, jobs (`ShouldQueue`, `Dispatchable`) | `event()`, `dispatch()`, façades, `Job::dispatch()` | `Route::get/post/…`, `match`, `resource`, groupes avec `prefix`, `routes/api.php` ; client `Http::` |
| **Ecotone** | `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`, clés de routage | `CommandBus`, `EventBus`, `DistributedBus`, `sendWithRouting()` | |
| **PrestaShop** | `#[AsCommandHandler]`, `#[AsQueryHandler]` | `CommandBusInterface::handle()` | routage YAML |
| **WordPress** | `add_action()`, `add_filter()` | `do_action()`, `apply_filters()` | |
| **Votre propre code** | attributs et tags de handler reconnus par leur nom, `__invoke`/`handle(Message $m)`, classes `*Handler` | types `*Bus`, `*Dispatcher`, `*Publisher`, `recordThat()` des agrégats, vos surcouches de bus | Guzzle, Symfony HttpClient, PSR-18 |

</details>

<details>
<summary><b>Architecture : couches, bounded contexts, règles en CI</b></summary>

La couche d'une classe se lit dans son namespace : le premier segment explicite (`Domain`, `Application`, `Infrastructure`, `UI`, `Presentation`, `Port`, `Adapter`, `UseCase`), ou à défaut le dernier segment générique (`Model`, `Entity`, `Controller`, `Http`, `Persistence`…). Le bounded context se lit avant la couche (`CodelyTv\Mooc\Courses\Domain`) ou après quand le projet met la couche en premier (`Core\Domain\Product`).

`phpgraph check` applique au code applicatif les règles par défaut d'une architecture en couches : `domain` ne dépend ni d'`application`, ni d'`infrastructure`, ni d'`interface` ; `application` et `port` ne dépendent ni d'`infrastructure`, ni d'`interface`. Sur un projet qui a déjà des violations :

```bash
phpgraph check --generate-baseline   # accepte les violations actuelles dans phpgraph-baseline.json
phpgraph check                       # échoue seulement sur les nouvelles
```

</details>

<details>
<summary><b>Microservices</b></summary>

Un dépôt qui contient au moins deux applications Composer indépendantes est découpé en services. Les paquets d'un monorepo restent dans leur projet : un paquet que la racine autoload, déclare en dépôt `path` ou `replace` en fait partie, ce n'est pas un service. Les identifiants portent alors leur service, `billing@App\Domain\Order`, et un nom ne se résout jamais dans un autre service : les services se rencontrent par des contrats.

- même classe de message envoyée par un service et traitée par un autre : `contract` ;
- clé de routage (`sendWithRouting('order.place', …)`, `#[CommandHandler('ticket.create')]`, événement nommé) : un nœud `channel:` partagé par tous les services ;
- appel HTTP vers une route d'un autre service : `requests`. Un appel dont le chemin correspond à une route déclarée pour d'autres méthodes HTTP seulement est signalé : il échouerait tel quel.

Un `phpgraph.yaml` facultatif nomme les services quand la détection ne convient pas :

```yaml
services:
  - services/billing
  - services/shipping
```

</details>

<details>
<summary><b>Fonctionnement</b></summary>

1. Chaque fichier PHP est analysé en faits : déclarations, appels avec l'expression qui type leur récepteur, envois de messages et handlers, routes, appels HTTP.
2. Le builder résout ces faits entre fichiers : types à travers l'héritage et les types de retour, signatures de `vendor/` lues à la demande dans les métadonnées de Composer (analysées, jamais exécutées), définitions de services lues dans la configuration du conteneur.
3. Le graphe est enregistré dans `phpgraph-out/graph.json`, avec `GRAPH_REPORT.md` pour les humains.

Les reconstructions sont incrémentales : l'extraction de chaque fichier est mise en cache selon son contenu, et le serveur MCP ne résout à nouveau que les appels des fichiers modifiés quand aucune déclaration n'a changé. Le résultat est identique à un build complet.

</details>

## Mesuré sur de vrais projets

phpgraph est développé sur un corpus de projets open source figés à un commit, et chaque évolution se juge sur leurs chiffres (`composer corpus:measure`). Code applicatif seulement ; *récepteur typé* est la part des appels de méthodes dont phpgraph a pu déterminer le type du récepteur.

| Projet | Type | Fichiers PHP | Build | Récepteur typé |
|---|---|---:|---:|---:|
| [php-ddd-example](https://github.com/CodelyTV/php-ddd-example) | DDD, CQRS | 304 | 0,4 s | 98,3 % |
| [Sylius](https://github.com/Sylius/Sylius) | e-commerce Symfony | 4 946 | 6,5 s | 79,6 % |
| [Akeneo PIM](https://github.com/akeneo/pim-community-dev) | hexagonal, bounded contexts | 8 416 | 10,3 s | 87,0 % |
| [PrestaShop](https://github.com/PrestaShop/PrestaShop) | CQRS et legacy | 7 850 | 10,0 s | 90,5 % |
| [Ecotone quickstart](https://github.com/ecotoneframework/quickstart-examples) | 47 services, messagerie | 583 | 0,6 s | 91,8 % |
| [BookStack](https://github.com/BookStackApp/BookStack) | Laravel | 1 513 | 2,8 s | 87,4 % |
| [WordPress](https://github.com/WordPress/WordPress) | sans framework, hooks | 1 899 | 5,3 s | 90,9 % |

Deux petits projets microservices complètent le corpus : [deux services Laravel reliés par RabbitMQ](https://github.com/mostafaaminflakes/Using-RabbitMQ-in-Microservices), avec 4 contrats de messages, et [quatre services Laravel reliés par HTTP](https://github.com/omarihab99/Art-Gallery), où phpgraph a trouvé un `POST` envoyé vers une route `GET` seulement. Aucun échec d'analyse sur environ 26 000 fichiers. Le détail par projet, messages, routes et services compris, est dans [`corpus/RESULTS.md`](https://github.com/hbenabdallah/phpgraph/blob/main/corpus/RESULTS.md).

### Avec un agent

Mesuré sur un projet Symfony/DDD privé de 4 800 fichiers, 3 exécutions par configuration, le même agent ([Sherpa](https://github.com/hbenabdallah/sherpa)) avec et sans phpgraph 1.0.2 :

| Type de question | Avec phpgraph | Qualité |
|---|---|---|
| Impact ou refactoring (`impact_of`) | −42 % de tokens, −50 % de temps, −59 % d'appels d'outils | identique ou meilleure |
| Expliquer une fonctionnalité (`outline`) | −25 % de tokens, −34 % de temps, −49 % d'appels d'outils ; −31 % de tokens avec l'outline par défaut | identique |

Les plages des exécutions avec et sans phpgraph ne se chevauchent pas.

### Comparé à d'autres graphes de code

Sylius (le commit du corpus), octobre 2026, sans LLM : phpgraph 1.0.2, [codebase-memory-mcp](https://github.com/DeusData/codebase-memory-mcp) 0.11.0 et [graphify](https://github.com/safishamsi/graphify) 0.9.76 (`--code-only`), sur la même machine, dans Docker. Les appels ont été comparés site par site (fichier, ligne, méthode), et des échantillons des différences vérifiés dans le code.

| | phpgraph | codebase-memory-mcp | graphify |
|---|---:|---:|---:|
| Build | 10 s | 17 s | 32 s |
| Sites d'appel reliés à une méthode du projet | 22 870 | 32 274 | 4 005 |
| dont résolus par les types | 22 747 | 4 314 | — |
| Appelants de `OrderItemQuantityModifierInterface::modify()` trouvés (17 dans le code) | 17, aucun faux | 11, 2 faux | 0, 1 faux |
| Handlers Messenger reliés à leur message | 206 | 0 | 0 |
| Injections du conteneur, routes | 1 360, 188 | 0, 1 (fausse) | 0, 0 |

- codebase-memory-mcp est d'accord avec phpgraph sur 99,7 % des appels qu'il résout par les types. Ses autres arêtes sont devinées par le nom de la méthode (confiance de 0,04 à 0,44) : là où les deux relient le même appel, ils divergent sur 9 400 sites, et ses 11 300 arêtes en plus sont surtout des appels vers `vendor/` rattachés à une méthode du projet de même nom (19 sur 20 vérifiées).
- graphify relie `$this->other->foo()` à `foo()` de la classe courante : juste pour `$this->foo()`, faux pour les 10 différences vérifiées sur 10.
- Ce qu'ils ont et que phpgraph n'a pas : d'autres langages, la recherche plein texte et vectorielle, les requêtes Cypher (codebase-memory-mcp), une visualisation interactive et des documents dans le graphe (graphify).

## FAQ

**phpgraph envoie-t-il mon code quelque part ?** Non. Il tourne en local, ne fait aucun appel réseau et n'utilise aucun LLM.

**Exécute-t-il mon code ?** Non. Il analyse les fichiers PHP et lit la configuration JSON, YAML et XML. Même `autoload_classmap.php` et les configurateurs de services PHP sont analysés, pas inclus.

**Quelles versions de PHP peut-il analyser ?** Tout ce que `nikic/php-parser` 5 sait lire : la syntaxe de PHP 7 et 8. phpgraph lui-même demande PHP 8.2 ou plus.

**Avec quels agents fonctionne-t-il ?** Tout client MCP sur stdio : [Sherpa](https://github.com/hbenabdallah/sherpa), Claude Code, Codex, Cursor, Claude Desktop, et d'autres. `mcp-config` affiche la configuration des plus courants.

**Quelle taille de projet supporte-t-il ?** Le plus gros projet du corpus, Akeneo, compte 8 400 fichiers : environ 10 s et 500 Mo pour un build complet, environ 3 s pour une reconstruction après une modification.

## Limites

- Les appels de fonctions globales et les appels dynamiques (`$this->$name()`, `__call`) ne sont pas résolus.
- Les génériques ne sont lus que pour les collections : un `foreach` sur un paramètre ou une propriété documentés `Foo[]`, `array<Foo>`, `list<Foo>` ou `iterable<Foo>` est typé ; `Collection<Foo>` et les autres classes génériques sont lues comme `Collection`.
- Les chaînes s'arrêtent aux méthodes magiques, aux classes internes de PHP et aux dépendances sans type de retour exploitable.
- Sans `vendor/` installé, les chaînes d'appels s'arrêtent à la première dépendance.
- Les services construits à l'exécution (passes de compilation, extensions de bundle, identifiants calculés à partir de valeurs connues seulement à l'exécution), les routes en XML, les ressources API Platform déclarées en XML ou YAML et les schémas d'API (OpenAPI, protobuf) ne sont pas lus ; les injections non reliées sont listées par `overview`.
- Deux services qui déclarent une classe de message du même nom sont supposés la partager.

## Contribuer

Les issues et les pull requests sont les bienvenues. Voir [CONTRIBUTING.md](https://github.com/hbenabdallah/phpgraph/blob/main/CONTRIBUTING.md) : `composer check` doit passer (php-cs-fixer, PHPStan niveau 8, PHPUnit), et les évolutions de l'analyse se jugent sur le corpus. Sans PHP installé, `bin/dev composer check` lance tout dans Docker.

Ce qui reste stable d'une version à l'autre (commandes, outils MCP, format de `graph.json`) est listé dans [docs/STABILITY.md](https://github.com/hbenabdallah/phpgraph/blob/main/docs/STABILITY.md) ; les changements sont dans le [changelog](https://github.com/hbenabdallah/phpgraph/blob/main/CHANGELOG.md).

Les versions se publient en poussant un tag : le [workflow de release](https://github.com/hbenabdallah/phpgraph/blob/main/.github/workflows/release.yml) construit le PHAR, les exécutables pour Linux, macOS et Windows (`tools/build-binary.sh`) et l'image Docker `ghcr.io/hbenabdallah/phpgraph`.

## Licence

phpgraph est distribué sous la [PolyForm Shield License 1.0.0](https://github.com/hbenabdallah/phpgraph/blob/main/LICENSE), une licence « source disponible » : vous pouvez l'utiliser, le modifier et le redistribuer, y compris en entreprise, pour tout usage qui ne concurrence pas phpgraph. Il est interdit de fournir, à partir de ce code, un produit concurrent. Ce n'est pas une licence open source approuvée par l'OSI.
