<?php

declare(strict_types=1);

namespace Semitexa\Ultimate;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * It sits at src/Capabilities.php like every package's, so the set of packages
 * carrying one is the whole set, with no exception to remember; the capability
 * catalog requires it by that path from the workspace checkout. This package is
 * a project skeleton, though, and a project's src/ is its own: .gitattributes
 * keeps the file out of the archive a new project is made from
 * (tk-init-command-lands-in-every-projects-src).
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'dist.ultimate',
    summary: 'The full-stack distribution: a project skeleton with the core packages, docs, container files and a working example module already wired.',
    useWhen: 'Starting a new Semitexa project, and the first commit should already boot, render and pass its tests.',
    avoidWhen: 'Adding Semitexa to an application that already exists — require the individual packages rather than adopting a skeleton around them.',
    replaces: [
        'assembling the package set, container files and module layout by hand for each new project',
        'copying the previous project and deleting whatever does not apply',
    ],
)]
final class Capabilities
{
}
