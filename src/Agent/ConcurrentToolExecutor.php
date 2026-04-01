<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Exception\TerminationException;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use React\Promise\PromiseInterface;

use function React\Async\async;
use function React\Async\await;
use function React\Async\parallel;

/**
 * Concurrent tool executor using ReactPHP Fibers.
 *
 * When multiple tools are batched, each is wrapped in a Fiber via
 * React\Async\async(). Fibers are cooperative and single-threaded —
 * concurrency comes from I/O suspension: when Tool A calls await()
 * (HTTP request, subprocess), Tool B's Fiber runs.
 *
 * Purely synchronous tools (read_file, list_dir) execute serially
 * with negligible Fiber overhead (~microseconds). The big wins are:
 * - Concurrent spawn_agent calls (multiple child LLM HTTP requests)
 * - Concurrent exec commands (parallel subprocesses)
 * - Concurrent web_fetch calls (parallel HTTP downloads)
 */
final class ConcurrentToolExecutor implements BatchToolExecutorInterface
{
    #[\Override]
    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        return $tool->execute($arguments);
    }

    #[\Override]
    public function executeBatch(array $batch): array
    {
        if (count($batch) <= 1) {
            // Single tool — skip Fiber overhead
            if (empty($batch)) {
                return [];
            }

            $entry = $batch[0];

            return [$entry['tool']->execute($entry['arguments'])];
        }

        // Build a task list for React\Async\parallel().
        // Each task is a callable that returns a Promise (via async()).
        // parallel() runs them all in separate Fibers and collects results.
        $tasks = [];
        foreach ($batch as $i => $entry) {
            $tool = $entry['tool'];
            $arguments = $entry['arguments'];

            $tasks[$i] = async(static function () use ($tool, $arguments): ToolResult {
                try {
                    return $tool->execute($arguments);
                } catch (TerminationException $e) {
                    // Re-throw — parallel() will reject and cancel remaining
                    throw $e;
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }
            });
        }

        try {
            /** @var ToolResult[] $results */
            $results = await(parallel($tasks));

            // parallel() preserves keys, but ensure array is ordered
            ksort($results);

            return array_values($results);
        } catch (TerminationException $e) {
            // A tool requested loop termination (e.g. restart_coqui).
            // parallel() cancelled remaining tasks via promise cancellation.
            // Re-throw so AbstractAgent's executeToolCalls() handles it.
            throw $e;
        }
    }
}
