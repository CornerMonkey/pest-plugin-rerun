<?php

declare(strict_types=1);

namespace Zitcha\PestOnlyFailed;

use Pest\Contracts\HasPrintableTestCaseName;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\Terminable;
use Pest\Plugins\Concerns\HandleArguments;
use Pest\Plugins\Parallel;
use Pest\Plugins\Parallel\Paratest\WrapperRunner;
use PHPUnit\Event\Code\Test as CodeTest;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\TestRunner\TestResult\Facade as ResultFacade;
use PHPUnit\TestRunner\TestResult\TestResult;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class OnlyFailedPlugin implements HandlesArguments, Terminable
{
    use HandleArguments;

    private const string STORAGE_FILE = '.pest-only-failed.json';

    private const string CHILD_ENV_VAR = 'PEST_ONLY_FAILED_CHILD';

    private bool $rerunFailed = false;

    private string $colors = 'always';

    public function handleArguments(array $arguments): array
    {
        $colorsCopy = $arguments;
        $this->colors = $this->popArgumentValue('--colors', $colorsCopy) ?? 'always';

        if ($this->hasArgument('--only-failed', $arguments)) {
            $arguments = $this->popArgument('--only-failed', $arguments);

            $ids = $this->readStoredIds();

            if ($ids !== []) {
                $arguments = $this->pushArgument($this->buildFilterArgument($ids), $arguments);
            }
        }

        if ($this->hasArgument('--rerun-failed', $arguments)) {
            $arguments = $this->popArgument('--rerun-failed', $arguments);

            $this->rerunFailed = true;
        }

        return $arguments;
    }

    public function terminate(): void
    {
        // Each paratest worker only ran a fragment of the suite and has no view of
        // the other workers' results. Only the coordinator process — which merges
        // every worker's result via WrapperRunner::$result once they've all
        // finished — may decide what to persist or rerun.
        if (Parallel::isEnabled() && Parallel::isWorker()) {
            return;
        }

        $ids = $this->failedTestIds();

        // The rerun below spawns a subprocess that loads this same plugin. It must not
        // overwrite the failure list written by the parent, or a test that fails in the
        // main pass but passes on rerun would silently disappear from the record.
        if (getenv(self::CHILD_ENV_VAR) !== false) {
            return;
        }

        file_put_contents(
            self::STORAGE_FILE,
            json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        if ($this->rerunFailed && $ids !== []) {
            $this->rerun($ids);
        }
    }

    /**
     * @return list<string>
     */
    private function failedTestIds(): array
    {
        $result = $this->currentResult();
        $ids = [];

        foreach ([...$result->testFailedEvents(), ...$result->testErroredEvents()] as $event) {
            if (! method_exists($event, 'test')) {
                // AfterLastTestMethodFailed/BeforeFirstTestMethodFailed and their Errored
                // equivalents report a hook failure, not a single test. There is no single
                // test id to filter on, so these can't be tracked for --only-failed/--rerun-failed.
                continue;
            }

            $ids[] = $this->idFor($event->test());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Under `--parallel`, this process is the coordinator (workers already returned
     * early in terminate()). Its own result facade is empty — it never runs any
     * tests itself — so the real, merged-across-all-workers result lives on
     * WrapperRunner::$result instead, populated once every worker has finished.
     */
    private function currentResult(): TestResult
    {
        if (Parallel::isEnabled()) {
            return WrapperRunner::$result ?? ResultFacade::result();
        }

        return ResultFacade::result();
    }

    /**
     * Builds the identifier used both for persistence and for the `--filter` value.
     *
     * Pest overrides PHPUnit's name filter to match functional test()/it() cases
     * against their printable name (the original description string) rather than
     * the internal, mangled method name — so the id must be built the same way,
     * or `--filter` will silently match nothing.
     */
    private function idFor(CodeTest $test): string
    {
        if (! $test instanceof TestMethod) {
            return $test->id();
        }

        $implements = class_implements($test->className()) ?: [];

        if (in_array(HasPrintableTestCaseName::class, $implements, true)) {
            $className = preg_replace('/^P\\\\/', '', $test->className(), 1);

            return $className.'::'.$test->testDox()->prettifiedMethodName();
        }

        return $test->className().'::'.$test->name();
    }

    /**
     * @param  list<string>  $ids
     */
    private function buildFilterArgument(array $ids): string
    {
        $pattern = implode('|', array_map(
            static fn (string $id): string => preg_quote($id, '/'),
            $ids,
        ));

        // A self-delimited /pattern/ bypasses Pest's `#4`/`@name` filter shorthand
        // entirely, which otherwise can mangle a multi-id alternation when one of
        // the ids ends in a numeric data set suffix (e.g. "...#0").
        return '--filter=/(?:'.$pattern.')/i';
    }

    /**
     * @return list<string>
     */
    private function readStoredIds(): array
    {
        if (! is_file(self::STORAGE_FILE)) {
            return [];
        }

        $contents = file_get_contents(self::STORAGE_FILE);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $ids = json_decode($contents, true);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /**
     * @param  list<string>  $ids
     */
    private function rerun(array $ids): void
    {
        fwrite(STDOUT, PHP_EOL.'  ─── Rerun of failed tests ───'.PHP_EOL.PHP_EOL);

        $process = new Process(
            [PHP_BINARY, 'vendor/bin/pest', $this->buildFilterArgument($ids), '--colors='.$this->colors],
            null,
            [self::CHILD_ENV_VAR => '1'],
        );

        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    }
}
