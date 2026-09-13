<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap shim — referenced from phpstan.neon's `bootstrapFiles`.
 *
 * Registers PSR-4 mappings for local modules (`src/modules/<Name>/src`) on the
 * live Composer ClassLoader, so PHPStan can resolve your own module classes.
 *
 * The runtime does this through the LocalModuleAutoloadPhase build phase.
 * PHPStan never runs build phases, so without this hook every reference to a
 * class in one of your modules analyses as an unknown class.
 *
 * The guard makes it a no-op on an installation whose semitexa/core predates
 * LocalModuleAutoloadRegistrar. Idempotent: addPsr4 merges directories.
 */

if (class_exists(\Semitexa\Core\Boot\LocalModuleAutoloadRegistrar::class)) {
    \Semitexa\Core\Boot\LocalModuleAutoloadRegistrar::register();
}
