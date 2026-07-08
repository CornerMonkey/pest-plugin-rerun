<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Fixture project helpers
|--------------------------------------------------------------------------
|
| These tests exercise the plugin process-isolation style: a small, real Pest
| project lives in tests/Fixtures/project (with this package required via a
| composer path repository) and is copied to a fresh temp directory for every
| test, so tests never interfere with each other's `.pest-only-failed.json`.
|
*/

function fixtureTemplatePath(): string
{
    return __DIR__.'/Fixtures/project';
}

/**
 * Installs the fixture project's own composer dependencies once. Its vendor/
 * directory is not committed, so the first test run pays this cost and every
 * later run (and every per-test copy) reuses the result.
 */
function ensureFixtureTemplateInstalled(): void
{
    $template = fixtureTemplatePath();

    if (is_dir($template.'/vendor')) {
        return;
    }

    $process = new Process(['composer', 'install', '--no-interaction'], $template);
    $process->setTimeout(300);
    $process->mustRun();
}

/**
 * Copies the fixture template (including its installed vendor/) into a fresh
 * temporary directory and returns the new project's path.
 */
function prepareFixtureProject(): string
{
    ensureFixtureTemplateInstalled();

    $target = sys_get_temp_dir().'/pest-only-failed-'.bin2hex(random_bytes(8));

    copyDirectory(fixtureTemplatePath(), $target);

    register_shutdown_function(fn () => removeDirectory($target));

    return $target;
}

function copyDirectory(string $source, string $destination): void
{
    mkdir($destination, recursive: true);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $target = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathName();

        if ($item->isDir()) {
            mkdir($target);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

/**
 * Overwrites the fixture project's single test file with the given contents.
 */
function writeFixtureTest(string $projectPath, string $contents): void
{
    file_put_contents($projectPath.'/tests/ExampleTest.php', $contents);
}

/**
 * Runs `pest` inside the given fixture project and returns the completed process.
 *
 * @param  list<string>  $arguments
 */
function runFixturePest(string $projectPath, array $arguments = []): Process
{
    $process = new Process([...['php', 'vendor/bin/pest', '--colors=never'], ...$arguments], $projectPath);
    $process->setTimeout(60);
    $process->run();

    return $process;
}

/**
 * @return list<string>
 */
function fixtureFailedIds(string $projectPath): array
{
    $file = $projectPath.'/.pest-only-failed.json';

    if (! is_file($file)) {
        return [];
    }

    $ids = json_decode((string) file_get_contents($file), true);

    return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
}
