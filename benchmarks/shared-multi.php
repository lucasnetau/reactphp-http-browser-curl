<?php declare(strict_types=1);
/**
 * Shared-multi vs per-request-multi benchmark.
 *
 * Runs the real Browser with the default one-CurlMultiHandle-per-request mode and with
 * withSharedMultiHandle(true) against a local server, for workloads that stress the
 * per-tick polling overhead (many long-lived slow transfers), head-of-line blocking
 * (one slow transfer started first plus many fast ones) and raw throughput.
 *
 * Usage:
 *   php benchmarks/shared-multi.php                 # run all workloads, 3 reps, median
 *   php benchmarks/shared-multi.php --reps=5
 *   php benchmarks/shared-multi.php --workload=mixed
 *   php benchmarks/shared-multi.php --mode=shared
 *
 * The "mixed" workload asserts that fast responses settle before the slow one in BOTH
 * modes (i.e. a shared multi handle does not make responses wait for all transfers).
 */
require __DIR__ . '/../vendor/autoload.php';

use EdgeTelemetrics\React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * @return list<array{0: string, 1: int}> [path, expected bytes]
 */
function workload(string $name): array
{
    return match ($name) {
        // many small, immediate responses: per-tick overhead at high concurrency
        'fast' => array_fill(0, 100, ['/fast?bytes=65536', 65536]),
        // many tiny, long-lived transfers: almost pure polling overhead (N execs per tick)
        'slow' => array_fill(0, 40, ['/stream?bytes=8192&ms=1000', 8192]),
        // one 2 s transfer started first + fast/medium transfers; proves per-completion settling
        'mixed' => array_merge(
            [['/stream?bytes=16384&ms=2000', 16384]],
            array_fill(0, 30, ['/fast?bytes=65536', 65536]),
            array_fill(0, 5, ['/stream?bytes=16384&ms=400', 16384]),
        ),
        // throughput
        'large' => array_fill(0, 8, ['/fast?bytes=8388608', 8388608]),
        // idle waiting for headers
        'delayed' => array_fill(0, 30, ['/delay?ms=300', 2]),
        default => throw new InvalidArgumentException('Unknown workload: ' . $name),
    };
}

function runChild(string $mode, string $workload, int $port): array
{
    $browser = new Browser([CURLOPT_TIMEOUT => 60]);
    $benchBufferSize = (int)getenv('BENCH_BUFFERSIZE');
    if ($benchBufferSize > 0) {
        //optional tuning knob for experiments, e.g. BENCH_BUFFERSIZE=262144
        $browser = new Browser([CURLOPT_TIMEOUT => 60, CURLOPT_BUFFERSIZE => $benchBufferSize]);
    }
    if ($mode === 'shared') {
        $browser = $browser->withSharedMultiHandle();
    } else {
        //shared is the default; force the legacy one-multi-per-request mode
        $browser = $browser->withSharedMultiHandle(false);
    }

    $base = 'http://127.0.0.1:' . $port;
    $requests = workload($workload);

    $completion = [];
    $lengths = [];
    $promises = [];
    foreach ($requests as $i => [$path, $expectedBytes]) {
        $promises[$i] = $browser->get($base . $path)->then(static function (ResponseInterface $response) use (&$completion, &$lengths, $i): void {
            $completion[$i] = hrtime(true);
            $lengths[$i] = strlen((string)$response->getBody());
        });
    }

    $usageStart = getrusage();
    $start = hrtime(true);
    React\Async\await(React\Promise\all($promises));
    $end = hrtime(true);
    $usageEnd = getrusage();

    $cpuMs = (($usageEnd['ru_utime.tv_sec'] - $usageStart['ru_utime.tv_sec']) * 1e3)
        + (($usageEnd['ru_utime.tv_usec'] - $usageStart['ru_utime.tv_usec']) / 1e3)
        + (($usageEnd['ru_stime.tv_sec'] - $usageStart['ru_stime.tv_sec']) * 1e3)
        + (($usageEnd['ru_stime.tv_usec'] - $usageStart['ru_stime.tv_usec']) / 1e3);

    $relativeMs = [];
    foreach ($completion as $i => $time) {
        //prefixed keys survive JSON encoding as an object (keys are request indices,
        //which are not in order because responses settle as they complete)
        $relativeMs['r' . $i] = ($time - $start) / 1e6;
    }

    $bytesOk = true;
    foreach ($requests as $i => [$path, $expectedBytes]) {
        if (($lengths[$i] ?? -1) !== $expectedBytes) {
            $bytesOk = false;
        }
    }

    return [
        'mode' => $mode,
        'workload' => $workload,
        'count' => count($requests),
        'wall_ms' => ($end - $start) / 1e6,
        'cpu_ms' => $cpuMs,
        'first_ms' => $relativeMs === [] ? null : min($relativeMs),
        'last_ms' => $relativeMs === [] ? null : max($relativeMs),
        'completion_ms' => $relativeMs,
        'bytes_ok' => $bytesOk,
    ];
}

function median(array $values): float
{
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);
    return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

// ---------------------------------------------------------------------------------------
// Child mode: execute one (mode, workload) run and print JSON
// ---------------------------------------------------------------------------------------
if (($argv[1] ?? '') === '--child') {
    $mode = $argv[2];
    $workload = $argv[3];
    $port = (int)$argv[4];

    try {
        $result = runChild($mode, $workload, $port);
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
        exit($result['bytes_ok'] ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------------------
// Parent mode: start server, run both modes for each workload, print medians
// ---------------------------------------------------------------------------------------

$reps = 3;
$modeFilter = null;
$workloadFilter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--reps=')) {
        $reps = max(1, (int)substr($arg, 7));
    } elseif (str_starts_with($arg, '--mode=')) {
        $modeFilter = substr($arg, 7);
    } elseif (str_starts_with($arg, '--workload=')) {
        $workloadFilter = substr($arg, 11);
    }
}

$serverProcess = proc_open(
    [PHP_BINARY, __DIR__ . '/bench-server.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
    $serverPipes
);
if (!is_resource($serverProcess)) {
    fwrite(STDERR, "Unable to start benchmark server\n");
    exit(1);
}

$portLine = fgets($serverPipes[1]);
if ($portLine === false || !str_starts_with($portLine, 'PORT=')) {
    fwrite(STDERR, "Benchmark server did not report a port\n");
    proc_terminate($serverProcess);
    exit(1);
}
$port = (int)substr(trim($portLine), 5);

$modes = $modeFilter !== null ? [$modeFilter] : ['per-request', 'shared'];
$workloads = $workloadFilter !== null ? [$workloadFilter] : ['fast', 'slow', 'mixed', 'large', 'delayed'];

printf("PHP %s, libcurl %s, %d reps, server port %d\n\n", PHP_VERSION, curl_version()['version'], $reps, $port);
printf(
    "%-9s %-9s %8s %10s %10s %11s %11s %9s %9s\n",
    'workload', 'mode', 'requests', 'bytes', 'wall(ms)', 'cpu(ms)', 'first(ms)', 'last(ms)', 'req/s'
);

$results = [];
$failures = [];

foreach ($workloads as $name) {
    $requests = workload($name);
    $totalBytes = array_sum(array_column($requests, 1));
    $expectedCount = count($requests);

    foreach ($modes as $mode) {
        $runs = [];
        // warm-up run (discarded) to stabilise caches/connection setup
        runChildViaProcess($mode, $name, $port);

        for ($i = 0; $i < $reps; $i++) {
            $run = runChildViaProcess($mode, $name, $port);
            if ($run === null) {
                $failures[] = "$name/$mode: child process failed";
                continue;
            }
            $runs[] = $run;
        }

        if ($runs === []) {
            continue;
        }

        $walls = array_map(static fn (array $run) => $run['wall_ms'], $runs);
        $cpus = array_map(static fn (array $run) => $run['cpu_ms'], $runs);
        $firsts = array_map(static fn (array $run) => $run['first_ms'], $runs);
        $lasts = array_map(static fn (array $run) => $run['last_ms'], $runs);

        $result = [
            'workload' => $name,
            'mode' => $mode,
            'count' => $expectedCount,
            'bytes' => $totalBytes,
            'wall_ms' => median($walls),
            'cpu_ms' => median($cpus),
            'first_ms' => median($firsts),
            'last_ms' => median($lasts),
            'completion_ms' => $runs[0]['completion_ms'],
        ];
        $results[$name][$mode] = $result;

        printf(
            "%-9s %-9s %8d %10s %10.1f %10.1f %11.1f %11.1f %9.0f\n",
            $name,
            $mode,
            $expectedCount,
            $totalBytes >= 1048576 ? round($totalBytes / 1048576, 1) . ' MiB' : round($totalBytes / 1024) . ' KiB',
            $result['wall_ms'],
            $result['cpu_ms'],
            $result['first_ms'],
            $result['last_ms'],
            $result['wall_ms'] > 0 ? $expectedCount / ($result['wall_ms'] / 1000) : 0
        );

        foreach ($runs as $run) {
            if (!$run['bytes_ok']) {
                $failures[] = "$name/$mode: response bodies were not intact";
            }
        }

        // Head-of-line blocking check for the mixed workload: request 0 is the slow transfer
        if ($name === 'mixed') {
            $completions = $result['completion_ms'];
            $slowMs = $completions['r0'];
            unset($completions['r0']);
            $fastMax = max($completions);
            $ok = $fastMax < $slowMs;
            printf(
                "          %-9s slow request settled at %.1f ms, fast/medium requests by %.1f ms -> %s\n",
                '',
                $slowMs,
                $fastMax,
                $ok ? 'NO head-of-line blocking' : 'HEAD-OF-LINE BLOCKING'
            );
            if (!$ok) {
                $failures[] = "$name/$mode: fast responses were delayed by the slow transfer";
            }
        }
    }
}

proc_terminate($serverProcess);
proc_close($serverProcess);

echo "\n";

// ---------------------------------------------------------------------------------------
// Summary: shared vs per-request delta
// ---------------------------------------------------------------------------------------
if ($modeFilter === null) {
    printf("%-9s %14s %14s %14s\n", 'workload', 'wall change', 'cpu change', 'cpu saved');
    foreach ($results as $name => $modesForWorkload) {
        if (!isset($modesForWorkload['per-request'], $modesForWorkload['shared'])) {
            continue;
        }
        $base = $modesForWorkload['per-request'];
        $shared = $modesForWorkload['shared'];
        $wallDelta = $shared['wall_ms'] - $base['wall_ms'];
        $cpuDelta = $shared['cpu_ms'] - $base['cpu_ms'];
        printf(
            "%-9s %13.1f%% %13.1f%% %12.1f ms\n",
            $name,
            $base['wall_ms'] > 0 ? ($wallDelta / $base['wall_ms']) * 100 : 0,
            $base['cpu_ms'] > 0 ? ($cpuDelta / $base['cpu_ms']) * 100 : 0,
            $base['cpu_ms'] - $shared['cpu_ms']
        );
    }
    echo "\n(negative wall/cpu change = shared multi handle is faster)\n";
}

if ($failures !== []) {
    echo "\nFAILURES:\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}

echo "\nAll response bodies intact; no head-of-line blocking detected.\n";

/**
 * Run one child benchmark process and decode its JSON result.
 */
function runChildViaProcess(string $mode, string $workload, int $port): ?array
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--child', $mode, $workload, (string)$port],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
        $pipes
    );
    if (!is_resource($process)) {
        return null;
    }

    $output = stream_get_contents($pipes[1]);
    $exitCode = proc_close($process);

    foreach (array_reverse(explode("\n", (string)$output)) as $line) {
        $line = trim($line);
        if ($line !== '' && str_starts_with($line, '{')) {
            try {
                return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }
    }

    if ($exitCode !== 0) {
        return null;
    }

    return null;
}
