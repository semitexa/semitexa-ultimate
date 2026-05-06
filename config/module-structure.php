<?php

declare(strict_types=1);

/**
 * Local module-structure extension for `packages/semitexa-ultimate`.
 *
 * STRICTLY ADDITIVE. Authorises ONLY the two scaffold-template payload
 * directories that the package needs at `src/` because its composer
 * `type: "project"` and PSR-4 autoload (`App\\: src/`,
 * `App\\Registry\\: src/registry/`) make this package's directory layout
 * the consumer-project's directory layout when shipped via
 * `composer create-project semitexa/ultimate`.
 *
 * SCOPE: applies only to `packages/semitexa-ultimate/src/`. It does NOT
 * make `src/modules/` or `src/registry/` valid in any other package, and
 * it does NOT apply to `src/modules/*` application modules elsewhere in
 * the monorepo.
 *
 * Authorised:
 *   - `src/modules/`  — scaffold app-module payload (e.g. `Hello/`); each
 *     sub-tree is a real Semitexa application module that gets emitted
 *     verbatim into the consumer project via `composer create-project`.
 *     Treated as `MODE_OPAQUE_INTERNAL` here because the contents are
 *     consumer-project app modules whose internal structure is governed
 *     by the global app-module rules when they live at the consumer's
 *     `src/modules/Name/` (i.e. they're validated AT the consumer site,
 *     not here, where they're scaffold payload).
 *   - `src/registry/` — `App\Registry\` PSR-4 target. Holds
 *     generated-payload classes in real consumer projects; ships as a
 *     `.gitkeep` placeholder in the scaffold-template package itself.
 *     Same OPAQUE_INTERNAL rationale.
 *
 * NOT authorised:
 *   - any package-root files outside the global packageRoot envelope
 *     (the legacy non-dot `gitignore` was removed in this revision —
 *     it was orphan drift, not a scaffold artifact: no installer reads
 *     it; the package's real git ignores are in `.gitignore`).
 *
 * Companion: `packages/semitexa-ultimate/composer.json` ("type": "project").
 */

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;

return new LocalModuleStructureExtension(
    package: 'ultimate',
    topLevelDirectories: [
        'modules',
        'registry',
    ],
    topLevelFiles: [],
    pathRules: [
        'modules' => new ModuleStructureRule(
            path: 'modules',
            mode: ModuleStructureRule::MODE_OPAQUE_INTERNAL,
            opaqueOwner: 'ultimate',
            opaqueReason: 'Scaffold app-module payload. Each sub-tree (e.g. Hello/) is a real Semitexa application module that gets shipped verbatim to consumer projects via `composer create-project semitexa/ultimate`; the validator-canonical structure for these modules lives at the CONSUMER site (src/modules/Name/), not here.',
            opaqueTodo: 'No follow-up planned: scaffold-template payload by design.',
            rationale: 'semitexa-ultimate-only: Composer-distributed project skeleton; PSR-4 maps App\\ to src/.',
        ),
        'registry' => new ModuleStructureRule(
            path: 'registry',
            mode: ModuleStructureRule::MODE_OPAQUE_INTERNAL,
            opaqueOwner: 'ultimate',
            opaqueReason: 'PSR-4 target for App\\Registry\\ in scaffolded consumer projects. Currently a `.gitkeep` placeholder; holds generated-payload classes once a consumer project starts using it.',
            opaqueTodo: 'No follow-up planned: scaffold-template payload by design.',
            rationale: 'semitexa-ultimate-only: Composer-distributed project skeleton; PSR-4 maps App\\Registry\\ to src/registry/.',
        ),
    ],
    docPath: null,
    reason: 'semitexa-ultimate is a Composer-distributed full-stack project skeleton (composer type=project). Its src/ layout IS the consumer-project layout; src/modules/ + src/registry/ are scaffold-template payload paths required by the App\\ + App\\Registry\\ PSR-4 autoload contract.',
);
