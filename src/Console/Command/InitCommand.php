<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'init', description: 'Create or sync Semitexa project scaffold from semitexa/ultimate')]
final class InitCommand extends Command
{
    private const DEFAULT_SWOOLE_PORT = 9502;

    private const PLACEHOLDER_PORT = '{{ default_swoole_port }}';

    /**
     * @var array<string, string>
     */
    private const SCAFFOLD_FILE_MAP = [
        'AGENTS.md' => 'AGENTS.md',
        'AI_ENTRY.md' => 'AI_ENTRY.md',
        'README.md' => 'README.md',
        'docs/AI_CONTEXT.md' => 'docs/AI_CONTEXT.md',
        'server.php' => 'server.php',
        '.env.default' => '.env.default',
        'Dockerfile' => 'Dockerfile',
        'docker-compose.yml' => 'docker-compose.yml',
        'docker-compose.nats.yml' => 'docker-compose.nats.yml',
        'docker-compose.mysql.yml' => 'docker-compose.mysql.yml',
        'docker-compose.redis.yml' => 'docker-compose.redis.yml',
        'docker-compose.ollama.yml' => 'docker-compose.ollama.yml',
        'phpunit.xml.dist' => 'phpunit.xml.dist',
        'bin/semitexa' => 'bin/semitexa',
        '.gitignore' => 'gitignore',
        'public/.htaccess' => 'public/.htaccess',
        'AI_NOTES.md' => 'AI_NOTES.md',
        'tests/.gitkeep' => 'tests/.gitkeep',
        'var/docs/.gitkeep' => 'var/docs/.gitkeep',
        'var/run/.gitkeep' => 'var/run/.gitkeep',
        'src/infrastructure/database/.gitkeep' => 'src/infrastructure/database/.gitkeep',
        'src/infrastructure/migrations/.gitkeep' => 'src/infrastructure/migrations/.gitkeep',
    ];

    /**
     * @var list<string>
     */
    private const FULL_INIT_DIRECTORY_SYNC = [
        'src/modules/Hello',
    ];

    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Create or sync Semitexa project scaffold from semitexa/ultimate')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Target directory (default: current working directory)', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('only-docs', null, InputOption::VALUE_NONE, 'Sync docs and scaffold from semitexa/ultimate for an existing project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $force = (bool) $input->getOption('force');
        $onlyDocs = (bool) $input->getOption('only-docs');

        $root = $dir !== null ? realpath($dir) : getcwd();
        if ($root === false || !is_dir($root)) {
            $io->error('Target directory does not exist or is not readable: ' . ($dir ?? getcwd()));
            return Command::FAILURE;
        }

        $scaffoldRoot = $this->findScaffoldRoot($root);
        if ($scaffoldRoot === null) {
            $io->error('Semitexa scaffold assets were not found. Install semitexa/ultimate or restore its scaffold files.');
            return Command::FAILURE;
        }

        if ($onlyDocs) {
            return $this->executeOnlyDocs($root, $scaffoldRoot, $io, $force);
        }

        $io->title('Semitexa project init');
        $io->text('Project root: ' . $root);
        $io->text('Scaffold source: ' . $scaffoldRoot);

        $dirs = [
            'bin',
            'docs',
            'public',
            'src/infrastructure/database',
            'src/infrastructure/migrations',
            'src/modules',
            'tests',
            'var/cache',
            'var/log',
            'var/docs',
            'var/run',
        ];

        foreach ($dirs as $path) {
            $fullPath = $root . '/' . $path;
            $alreadyExists = is_dir($fullPath);
            if (!$this->ensureDirectory($fullPath)) {
                $io->error('Failed to create directory: ' . $path);
                return Command::FAILURE;
            }
            if (!$alreadyExists) {
                $io->text('Created: ' . $path . '/');
            }
        }

        foreach (['var/cache', 'var/log'] as $path) {
            $gitkeep = $root . '/' . $path . '/.gitkeep';
            if (!file_exists($gitkeep)) {
                file_put_contents($gitkeep, '');
            }
        }

        $syncFiles = [
            'AGENTS.md',
            'AI_ENTRY.md',
            'docs/AI_CONTEXT.md',
            'README.md',
            'server.php',
            '.env.default',
            'Dockerfile',
            'docker-compose.yml',
            'docker-compose.nats.yml',
            'docker-compose.mysql.yml',
            'docker-compose.redis.yml',
            'docker-compose.ollama.yml',
            'phpunit.xml.dist',
            'bin/semitexa',
            '.gitignore',
            'public/.htaccess',
            'tests/.gitkeep',
            'var/docs/.gitkeep',
            'var/run/.gitkeep',
            'src/infrastructure/database/.gitkeep',
            'src/infrastructure/migrations/.gitkeep',
        ];

        [$writtenFiles, $skippedFiles] = $this->writeFiles($root, $scaffoldRoot, $syncFiles, $force, false, $io);
        if ($writtenFiles === null || $skippedFiles === null) {
            return Command::FAILURE;
        }

        if (!$this->ensureLocalEnvOverrideFile($root, $io)) {
            return Command::FAILURE;
        }

        foreach (self::FULL_INIT_DIRECTORY_SYNC as $directory) {
            if (!$this->syncDirectory($root, $scaffoldRoot . '/' . $directory, $root . '/' . $directory, $force, $io, $writtenFiles, $skippedFiles)) {
                return Command::FAILURE;
            }
        }

        foreach ($writtenFiles as $path) {
            $io->text('Written: ' . $path);
        }
        foreach ($skippedFiles as $path) {
            $io->note('Skipped (exists): ' . $path . ' (use --force to overwrite)');
        }

        $agentsPath = $root . '/AGENTS.md';
        if (!file_exists($agentsPath)) {
            [$agentsWritten] = $this->writeFiles($root, $scaffoldRoot, ['AGENTS.md'], true, true, $io);
            if ($agentsWritten === null) {
                return Command::FAILURE;
            }
            if ($agentsWritten !== []) {
                $io->text('Written: AGENTS.md (AI session start protocol; never overwritten by framework)');
            }
        }

        $aiNotesPath = $root . '/AI_NOTES.md';
        if (!file_exists($aiNotesPath)) {
            [$notesWritten] = $this->writeFiles($root, $scaffoldRoot, ['AI_NOTES.md'], true, true, $io);
            if ($notesWritten === null) {
                return Command::FAILURE;
            }
            if ($notesWritten !== []) {
                $io->text('Written: AI_NOTES.md (your notes; never overwritten by framework)');
            }
        }

        if (!$this->patchComposerAutoload($root, $io, $force)) {
            return Command::FAILURE;
        }

        $io->success('Project structure created.');
        $io->text([
            'Next steps:',
            '  1. Review .env.default for the committed local baseline',
            '  2. Edit .env for local overrides when you need them',
            '  3. composer dump-autoload (if autoload was added)',
            '  4. Review the example module under src/modules/Hello/',
            '  5. Run: bin/semitexa server:start (Docker)',
        ]);

        return Command::SUCCESS;
    }

    private function executeOnlyDocs(string $root, string $scaffoldRoot, SymfonyStyle $io, bool $force): int
    {
        $io->title('Semitexa docs & scaffold sync');
        $io->text('Project root: ' . $root);
        $io->text('Scaffold source: ' . $scaffoldRoot);

        $syncFiles = [
            'AGENTS.md',
            'AI_ENTRY.md',
            'docs/AI_CONTEXT.md',
            'README.md',
            'server.php',
            '.env.default',
            'Dockerfile',
            'docker-compose.yml',
            'docker-compose.nats.yml',
            'docker-compose.mysql.yml',
            'docker-compose.redis.yml',
            'docker-compose.ollama.yml',
            'phpunit.xml.dist',
            'bin/semitexa',
            '.gitignore',
            'public/.htaccess',
        ];

        [$written, $skipped] = $this->writeFiles($root, $scaffoldRoot, $syncFiles, $force, false, $io);
        if ($written === null || $skipped === null) {
            return Command::FAILURE;
        }

        if (!$this->ensureLocalEnvOverrideFile($root, $io)) {
            return Command::FAILURE;
        }

        foreach ($written as $path) {
            $io->text('Written: ' . $path);
        }
        foreach ($skipped as $path) {
            $io->note('Skipped (exists): ' . $path . ' (use --force to overwrite)');
        }

        $io->success('Docs and scaffold (AGENTS, AI_ENTRY, docs/AI_CONTEXT, README, server.php, .env.default, Dockerfile, docker-compose (+ mysql, redis, nats, ollama overlays), phpunit, bin/semitexa, .gitignore, public/.htaccess) synced from semitexa/ultimate.');
        $io->text('.env.default stays committed as the baseline. Edit .env for local overrides when you need them.');

        return Command::SUCCESS;
    }

    private function patchComposerAutoload(string $root, SymfonyStyle $io, bool $force): bool
    {
        $path = $root . '/composer.json';
        if (!is_file($path)) {
            return true;
        }

        $fileContent = file_get_contents($path);
        if ($fileContent === false) {
            $io->error('Failed to read composer.json.');
            return false;
        }

        $json = json_decode($fileContent, true);
        if (!is_array($json)) {
            $io->error('composer.json is not valid JSON.');
            return false;
        }

        $autoload = $json['autoload'] ?? [];
        $psr4 = $autoload['psr-4'] ?? [];
        if (isset($psr4['App\\']) && !$force) {
            if (!isset($psr4['App\\Modules\\'])) {
                $psr4['App\\Modules\\'] = 'src/modules/';
                $json['autoload'] = array_merge($autoload, ['psr-4' => $psr4]);
                $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if ($encoded === false) {
                    $io->error('Failed to encode composer.json.');
                    return false;
                }
                if (file_put_contents($path, $encoded) === false) {
                    $io->error('Failed to update composer.json.');
                    return false;
                }
                $io->text('Updated composer.json: autoload.psr-4 "App\\Modules\\": "src/modules/"');
            }
            return true;
        }

        $psr4['App\\'] = 'src/';
        $psr4['App\\Tests\\'] = 'tests/';
        $psr4['App\\Modules\\'] = 'src/modules/';
        $json['autoload'] = array_merge($autoload, ['psr-4' => $psr4]);

        $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            $io->error('Failed to encode composer.json.');
            return false;
        }

        if (file_put_contents($path, $encoded) === false) {
            $io->error('Failed to update composer.json.');
            return false;
        }

        $io->text('Updated composer.json: autoload.psr-4 "App\\": "src/", "App\\Tests\\": "tests/", "App\\Modules\\": "src/modules/"');

        return true;
    }

    private function ensureLocalEnvOverrideFile(string $root, SymfonyStyle $io): bool
    {
        $path = $root . '/.env';
        if (file_exists($path)) {
            return true;
        }

        $content = <<<EOF
# Local overrides for Semitexa.
# Keep this file uncommitted.
# Add machine-specific values here when you need them.
EOF;

        if (file_put_contents($path, $content . PHP_EOL) === false) {
            $io->error('Failed to write .env override file.');
            return false;
        }

        $io->text('Written: .env (local overrides)');

        return true;
    }

    private function findScaffoldRoot(string $projectRoot): ?string
    {
        $candidates = [
            dirname(__DIR__, 3),
            $projectRoot . '/vendor/semitexa/ultimate',
            $projectRoot . '/packages/semitexa-ultimate',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/bin/semitexa') && is_file($candidate . '/AGENTS.md') && is_file($candidate . '/AI_ENTRY.md')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $projectPaths
     * @return array{0: list<string>|null, 1: list<string>|null}
     */
    private function writeFiles(string $root, string $scaffoldRoot, array $projectPaths, bool $force, bool $createOnly, SymfonyStyle $io): array
    {
        $written = [];
        $skipped = [];

        foreach ($projectPaths as $projectPath) {
            $sourceRelativePath = self::SCAFFOLD_FILE_MAP[$projectPath] ?? null;
            if ($sourceRelativePath === null) {
                $io->error('Unknown scaffold file mapping: ' . $projectPath);
                return [null, null];
            }

            $targetPath = $root . '/' . $projectPath;
            if (file_exists($targetPath) && (!$force || $createOnly)) {
                $skipped[] = $projectPath;
                continue;
            }

            $sourcePath = $scaffoldRoot . '/' . $sourceRelativePath;
            if (!is_readable($sourcePath)) {
                $io->error('Required scaffold asset missing: ' . $sourceRelativePath . ' in ' . $scaffoldRoot);
                return [null, null];
            }

            $targetDir = dirname($targetPath);
            if (!$this->ensureDirectory($targetDir)) {
                $io->error('Failed to create directory: ' . $targetDir);
                return [null, null];
            }

            $content = file_get_contents($sourcePath);
            if ($content === false) {
                $io->error('Failed to read scaffold asset: ' . $sourceRelativePath);
                return [null, null];
            }

            $content = str_replace(self::PLACEHOLDER_PORT, (string) self::DEFAULT_SWOOLE_PORT, $content);
            if (file_put_contents($targetPath, $content) === false) {
                $io->error('Failed to write: ' . $projectPath);
                return [null, null];
            }

            if ($projectPath === 'bin/semitexa') {
                @chmod($targetPath, 0755);
            }

            $written[] = $projectPath;
        }

        return [$written, $skipped];
    }

    /**
     * @param list<string> $written
     * @param list<string> $skipped
     */
    private function syncDirectory(string $projectRoot, string $sourceDir, string $targetDir, bool $force, SymfonyStyle $io, array &$written, array &$skipped): bool
    {
        if (!is_dir($sourceDir)) {
            $io->error('Required scaffold directory missing: ' . $sourceDir);
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
            $projectPath = ltrim(substr($targetDir . '/' . $relativePath, strlen($projectRoot)), '/');

            if ($item->isDir()) {
                if (!$this->ensureDirectory($item->getPathname() === $sourceDir ? $targetDir : $targetDir . '/' . $relativePath)) {
                    $io->error('Failed to create directory: ' . $targetDir . '/' . $relativePath);
                    return false;
                }
                continue;
            }

            $destinationPath = $targetDir . '/' . $relativePath;
            if (file_exists($destinationPath) && !$force) {
                $skipped[] = $projectPath;
                continue;
            }

            if (!$this->ensureDirectory(dirname($destinationPath))) {
                $io->error('Failed to create directory: ' . dirname($destinationPath));
                return false;
            }

            $content = file_get_contents($item->getPathname());
            if ($content === false) {
                $io->error('Failed to read scaffold asset: ' . $item->getPathname());
                return false;
            }

            if (file_put_contents($destinationPath, $content) === false) {
                $io->error('Failed to write: ' . $projectPath);
                return false;
            }

            $written[] = $projectPath;
        }

        return true;
    }

    private function ensureDirectory(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0755, true);
    }
}
