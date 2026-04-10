<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\WatchJob\ScheduleFileWatchJob;
use CoquiBot\Coqui\Api\WorkspaceWatcher;
use CoquiBot\Coqui\Contract\WatchJobInterface;
use CoquiBot\Coqui\Contract\WatchJobResult;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

// --- WorkspaceWatcher ---

test('watcher tick returns empty results when no jobs registered', function () {
    $watcher = new WorkspaceWatcher();
    $results = $watcher->tick();

    expect($results)->toBeEmpty();
});

test('watcher registers and ticks jobs', function () {
    $watcher = new WorkspaceWatcher();

    $mockJob = new class implements WatchJobInterface {
        public int $scanCount = 0;

        public function scan(): WatchJobResult
        {
            $this->scanCount++;
            return new WatchJobResult(added: 1);
        }

        public function name(): string
        {
            return 'test-job';
        }
    };

    $watcher->register($mockJob);
    expect($watcher->hasJobs())->toBeTrue();
    expect($watcher->getJobNames())->toBe(['test-job']);

    $results = $watcher->tick();
    expect($results)->toHaveKey('test-job');
    expect($results['test-job']->added)->toBe(1);
    expect($mockJob->scanCount)->toBe(1);
});

test('watcher catches exceptions from jobs', function () {
    $watcher = new WorkspaceWatcher();

    $failingJob = new class implements WatchJobInterface {
        public function scan(): WatchJobResult
        {
            throw new \RuntimeException('Scan failed');
        }

        public function name(): string
        {
            return 'failing-job';
        }
    };

    $watcher->register($failingJob);
    $results = $watcher->tick();

    expect($results)->toHaveKey('failing-job');
    expect($results['failing-job']->hasErrors())->toBeTrue();
    expect($results['failing-job']->errors[0])->toBe('Scan failed');
});

test('watcher stores last results', function () {
    $watcher = new WorkspaceWatcher();

    $mockJob = new class implements WatchJobInterface {
        public function scan(): WatchJobResult
        {
            return new WatchJobResult(modified: 2);
        }

        public function name(): string
        {
            return 'track-job';
        }
    };

    $watcher->register($mockJob);
    expect($watcher->getLastResults())->toBeEmpty();

    $watcher->tick();
    $last = $watcher->getLastResults();

    expect($last)->toHaveKey('track-job');
    expect($last['track-job']->modified)->toBe(2);
});

// --- WatchJobResult ---

test('WatchJobResult reports changes correctly', function () {
    $empty = new WatchJobResult();
    expect($empty->hasChanges())->toBeFalse();
    expect($empty->total())->toBe(0);

    $withChanges = new WatchJobResult(added: 1, modified: 2, removed: 3);
    expect($withChanges->hasChanges())->toBeTrue();
    expect($withChanges->total())->toBe(6);
});

test('WatchJobResult reports errors correctly', function () {
    $clean = new WatchJobResult(added: 1);
    expect($clean->hasErrors())->toBeFalse();

    $withErrors = new WatchJobResult(errors: ['bad file']);
    expect($withErrors->hasErrors())->toBeTrue();
});

// --- ScheduleFileWatchJob integration ---

beforeEach(function () {
    $this->testDir = sys_get_temp_dir() . '/coqui-watcher-test-' . bin2hex(random_bytes(8));
    $this->schedulesDir = $this->testDir . '/schedules';
    mkdir($this->schedulesDir, 0755, true);

    $this->dbPath = $this->testDir . '/test.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->scheduleStore = new ScheduleStore($this->storage->getPdo());
});

afterEach(function () {
    // Clean up test directory
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->testDir);
});

test('ScheduleFileWatchJob detects new files', function () {
    $job = new ScheduleFileWatchJob($this->schedulesDir, $this->scheduleStore);

    // First scan — empty
    $result = $job->scan();
    expect($result->added)->toBe(0);

    // Add a file
    file_put_contents($this->schedulesDir . '/daily-backup.json', json_encode([
        'schedule_expression' => '0 2 * * *',
        'prompt' => 'Run daily backup',
    ]));

    $result = $job->scan();
    expect($result->added)->toBe(1);
    expect($result->modified)->toBe(0);
    expect($result->removed)->toBe(0);

    // Check it was synced to the store
    $schedule = $this->scheduleStore->getByName('daily-backup');
    expect($schedule)->not->toBeNull();
    expect($schedule['source'])->toBe('filesystem');
    expect($schedule['prompt'])->toBe('Run daily backup');
});

test('ScheduleFileWatchJob detects modified files', function () {
    $job = new ScheduleFileWatchJob($this->schedulesDir, $this->scheduleStore);

    $filePath = $this->schedulesDir . '/my-task.json';
    file_put_contents($filePath, json_encode([
        'schedule_expression' => '*/5 * * * *',
        'prompt' => 'Original prompt',
    ]));

    // Initial scan
    $job->scan();

    // Modify with a different mtime
    sleep(1); // Ensure mtime changes
    file_put_contents($filePath, json_encode([
        'schedule_expression' => '*/10 * * * *',
        'prompt' => 'Updated prompt',
    ]));

    $result = $job->scan();
    expect($result->modified)->toBe(1);
    expect($result->added)->toBe(0);

    $schedule = $this->scheduleStore->getByName('my-task');
    expect($schedule['prompt'])->toBe('Updated prompt');
    expect($schedule['schedule_expression'])->toBe('*/10 * * * *');
});

test('ScheduleFileWatchJob detects removed files', function () {
    $job = new ScheduleFileWatchJob($this->schedulesDir, $this->scheduleStore);

    $filePath = $this->schedulesDir . '/temp-schedule.json';
    file_put_contents($filePath, json_encode([
        'schedule_expression' => '* * * * *',
        'prompt' => 'Temporary',
    ]));

    // Initial scan
    $job->scan();
    expect($this->scheduleStore->getByName('temp-schedule'))->not->toBeNull();

    // Remove the file
    unlink($filePath);

    $result = $job->scan();
    expect($result->removed)->toBe(1);
    expect($this->scheduleStore->getByName('temp-schedule'))->toBeNull();
});

test('ScheduleFileWatchJob handles malformed JSON as error', function () {
    $job = new ScheduleFileWatchJob($this->schedulesDir, $this->scheduleStore);

    file_put_contents($this->schedulesDir . '/bad.json', '{not valid}');

    $result = $job->scan();
    expect($result->hasErrors())->toBeTrue();
    expect($result->errors[0])->toContain('bad.json');
    expect($result->added)->toBe(0);
});

test('ScheduleFileWatchJob ignores non-json files', function () {
    $job = new ScheduleFileWatchJob($this->schedulesDir, $this->scheduleStore);

    file_put_contents($this->schedulesDir . '/readme.txt', 'Not a schedule');
    file_put_contents($this->schedulesDir . '/valid.json', json_encode([
        'schedule_expression' => '* * * * *',
        'prompt' => 'Valid',
    ]));

    $result = $job->scan();
    expect($result->added)->toBe(1);
    expect($this->scheduleStore->getByName('readme'))->toBeNull();
    expect($this->scheduleStore->getByName('valid'))->not->toBeNull();
});

test('ScheduleFileWatchJob handles missing directory gracefully', function () {
    $job = new ScheduleFileWatchJob('/nonexistent/path', $this->scheduleStore);

    $result = $job->scan();
    expect($result->added)->toBe(0);
    expect($result->hasErrors())->toBeFalse();
});
