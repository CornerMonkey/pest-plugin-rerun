<?php

declare(strict_types=1);

test('--parallel merges failures from every worker into one failure record', function () {
    $project = prepareFixtureProject();

    // Four files (not two) so that even if paratest's scheduling happens to hand
    // both of two files to the same free worker first, there are still enough
    // files left that the second worker can't stay idle for the whole run. Each
    // test records its own PID, so the assertions below can *prove* more than
    // one worker process actually executed tests, rather than just trusting
    // that a correct final answer means cross-process merging was exercised.
    writeFixtureTestFiles($project, [
        'AlphaTest.php' => <<<'PHP'
            <?php

            test('alpha fails', function () {
                file_put_contents(__DIR__.'/../alpha.pid', (string) getmypid());
                expect(true)->toBeFalse();
            });
            PHP,
        'BetaTest.php' => <<<'PHP'
            <?php

            test('beta fails', function () {
                file_put_contents(__DIR__.'/../beta.pid', (string) getmypid());
                expect(true)->toBeFalse();
            });

            test('beta passes', function () {
                expect(true)->toBeTrue();
            });
            PHP,
        'GammaTest.php' => <<<'PHP'
            <?php

            test('gamma passes', function () {
                file_put_contents(__DIR__.'/../gamma.pid', (string) getmypid());
                expect(true)->toBeTrue();
            });
            PHP,
        'DeltaTest.php' => <<<'PHP'
            <?php

            test('delta passes', function () {
                file_put_contents(__DIR__.'/../delta.pid', (string) getmypid());
                expect(true)->toBeTrue();
            });
            PHP,
    ]);

    runFixturePest($project, ['--parallel', '--processes=2']);

    $pids = array_map(
        fn (string $name): string => trim((string) file_get_contents($project.'/'.$name.'.pid')),
        ['alpha', 'beta', 'gamma', 'delta'],
    );

    expect($pids)->not->toContain('')
        ->and(count(array_unique($pids)))->toBeGreaterThan(1)
        ->and(fixtureFailedIds($project))->toEqualCanonicalizing([
            'Tests\AlphaTest::alpha fails',
            'Tests\BetaTest::beta fails',
        ]);
});

test('--only-failed composes with --parallel', function () {
    $project = prepareFixtureProject();

    writeFixtureTestFiles($project, [
        'AlphaTest.php' => <<<'PHP'
            <?php

            test('alpha fails', function () {
                expect(true)->toBeFalse();
            });
            PHP,
        'BetaTest.php' => <<<'PHP'
            <?php

            test('beta passes', function () {
                expect(true)->toBeTrue();
            });
            PHP,
    ]);

    runFixturePest($project, ['--parallel', '--processes=2']);

    $result = runFixturePest($project, ['--only-failed', '--parallel', '--processes=2']);

    expect($result->getOutput())->toContain('1 failed');
});

test('--rerun-failed composes with --parallel: one coordinated rerun, run serially', function () {
    $project = prepareFixtureProject();

    // As above: four files (with PID markers) rather than two, so the assertions
    // below can confirm the two failures actually came from separate worker
    // processes. Without that proof, this test could pass just as easily against
    // the pre-fix code if both failing files happened to land on the same
    // worker — that worker's own (uncoordinated) terminate() would then see
    // both failures directly and could produce a correct-looking rerun on its
    // own, without ever exercising the cross-worker merge this test exists to guard.
    writeFixtureTestFiles($project, [
        'AlphaTest.php' => <<<'PHP'
            <?php

            test('alpha fails', function () {
                file_put_contents(__DIR__.'/../alpha.pid', (string) getmypid());
                expect(true)->toBeFalse();
            });
            PHP,
        'BetaTest.php' => <<<'PHP'
            <?php

            test('beta fails', function () {
                file_put_contents(__DIR__.'/../beta.pid', (string) getmypid());
                expect(true)->toBeFalse();
            });
            PHP,
        'GammaTest.php' => <<<'PHP'
            <?php

            test('gamma passes', function () {
                file_put_contents(__DIR__.'/../gamma.pid', (string) getmypid());
                expect(true)->toBeTrue();
            });
            PHP,
        'DeltaTest.php' => <<<'PHP'
            <?php

            test('delta passes', function () {
                file_put_contents(__DIR__.'/../delta.pid', (string) getmypid());
                expect(true)->toBeTrue();
            });
            PHP,
    ]);

    $result = runFixturePest($project, ['--rerun-failed', '--parallel', '--processes=2']);
    $output = $result->getOutput();
    $sections = explode('Rerun of failed tests', $output);

    $pids = array_map(
        fn (string $name): string => trim((string) file_get_contents($project.'/'.$name.'.pid')),
        ['alpha', 'beta', 'gamma', 'delta'],
    );

    expect($pids)->not->toContain('')
        ->and(count(array_unique($pids)))->toBeGreaterThan(1)
        ->and($sections)->toHaveCount(2)
        ->and($sections[1])->toContain('alpha fails')
        ->and($sections[1])->toContain('beta fails')
        ->and($sections[1])->not->toContain('Parallel:')
        ->and($result->isSuccessful())->toBeFalse();
});

test('a failing test is recorded and a passing test is not', function () {
    $project = prepareFixtureProject();

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('alpha fails', function () {
            expect(true)->toBeFalse();
        });

        test('beta passes', function () {
            expect(true)->toBeTrue();
        });
        PHP);

    runFixturePest($project);

    expect(fixtureFailedIds($project))->toBe(['Tests\ExampleTest::alpha fails']);
});

test('fixing the failing test clears the failure record', function () {
    $project = prepareFixtureProject();

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('alpha fails', function () {
            expect(true)->toBeFalse();
        });
        PHP);

    runFixturePest($project);
    expect(fixtureFailedIds($project))->not->toBe([]);

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('alpha fails', function () {
            expect(true)->toBeTrue();
        });
        PHP);

    runFixturePest($project);

    expect(fixtureFailedIds($project))->toBe([]);
});

test('--only-failed runs just the previously failed tests', function () {
    $project = prepareFixtureProject();

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('alpha fails', function () {
            file_put_contents(__DIR__.'/../alpha.marker', 'ran', FILE_APPEND);
            expect(true)->toBeFalse();
        });

        test('beta passes', function () {
            file_put_contents(__DIR__.'/../beta.marker', 'ran', FILE_APPEND);
            expect(true)->toBeTrue();
        });
        PHP);

    runFixturePest($project);

    @unlink($project.'/alpha.marker');
    @unlink($project.'/beta.marker');

    $result = runFixturePest($project, ['--only-failed']);

    expect($result->getOutput())->toContain('1 failed')
        ->and(is_file($project.'/alpha.marker'))->toBeTrue()
        ->and(is_file($project.'/beta.marker'))->toBeFalse();
});

test('--only-failed with no failure record runs the full suite without error', function () {
    $project = prepareFixtureProject();

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('alpha passes', function () {
            expect(true)->toBeTrue();
        });

        test('beta passes', function () {
            expect(true)->toBeTrue();
        });
        PHP);

    $result = runFixturePest($project, ['--only-failed']);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->getOutput())->toContain('2 passed');
});

test('--rerun-failed reruns exactly the failed tests and reports a distinct section', function () {
    $project = prepareFixtureProject();

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('alpha fails', function () {
            file_put_contents(__DIR__.'/../alpha.marker', 'x', FILE_APPEND);
            expect(true)->toBeFalse();
        });

        test('beta passes', function () {
            file_put_contents(__DIR__.'/../beta.marker', 'x', FILE_APPEND);
            expect(true)->toBeTrue();
        });
        PHP);

    $result = runFixturePest($project, ['--rerun-failed']);

    expect($result->getOutput())->toContain('Rerun of failed tests')
        ->and(file_get_contents($project.'/alpha.marker'))->toBe('xx')
        ->and(file_get_contents($project.'/beta.marker'))->toBe('x')
        ->and($result->isSuccessful())->toBeFalse();
});

test('--rerun-failed reports a flaky test as passing on rerun but the run still fails overall', function () {
    $project = prepareFixtureProject();

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('flaky test', function () {
            $counterFile = __DIR__.'/../flaky-counter.txt';
            $count = is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
            file_put_contents($counterFile, (string) ($count + 1));

            expect($count)->toBeGreaterThan(0);
        });
        PHP);

    $result = runFixturePest($project, ['--rerun-failed']);
    $output = $result->getOutput();
    $sections = explode('Rerun of failed tests', $output, 2);

    expect($sections)->toHaveCount(2)
        ->and($sections[1])->toContain('PASS')
        ->and($result->isSuccessful())->toBeFalse();
});

test('a failing dataset entry survives the id round-trip for --only-failed and --rerun-failed', function () {
    $project = prepareFixtureProject();

    writeFixtureTest($project, <<<'PHP'
        <?php

        test('gamma dataset', function (bool $value) {
            expect($value)->toBeFalse();
        })->with([true, false]);
        PHP);

    runFixturePest($project);

    expect(fixtureFailedIds($project))->toHaveCount(1);

    $onlyFailedResult = runFixturePest($project, ['--only-failed']);
    expect($onlyFailedResult->getOutput())->toContain('1 failed');

    $rerunResult = runFixturePest($project, ['--rerun-failed']);
    expect($rerunResult->getOutput())->toContain('Rerun of failed tests');
});
