<?php

declare(strict_types=1);

use CoquiBot\Coqui\Command\DoctorCommand;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Tester\CommandTester;

test('doctor tolerates a leftover legacy evaluations table through the full database check path', function () {
    $workspace = sys_get_temp_dir() . '/coqui-doctor-' . bin2hex(random_bytes(8));
    mkdir($workspace, 0755, true);

    foreach (['data', 'src', 'skills', 'roles', 'loops', 'schedules', 'vendor'] as $dir) {
        mkdir($workspace . '/' . $dir, 0755, true);
    }

    file_put_contents($workspace . '/composer.json', json_encode(['name' => 'test/workspace'], JSON_THROW_ON_ERROR));
    file_put_contents($workspace . '/vendor/autoload.php', "<?php\n");

    $dbPath = $workspace . '/data/coqui.db';
    $storage = new SessionStorage($dbPath);
    $pdo = $storage->getPdo();

    try {
        $pdo->exec('DROP TABLE IF EXISTS evaluations');
        $pdo->exec(<<<'SQL'
            CREATE TABLE evaluations (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                evaluator_task_id TEXT,
                overall_grade TEXT NOT NULL,
                score_completion REAL NOT NULL,
                score_hallucination REAL NOT NULL,
                score_efficiency REAL NOT NULL,
                overall_score REAL NOT NULL,
                report TEXT NOT NULL,
                model TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $tester = new CommandTester(new DoctorCommand());
        $exitCode = $tester->execute([
            '--json' => true,
            '--workspace' => $workspace,
            '--workdir' => $workspace,
        ]);

        $output = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        expect($exitCode)->not->toBe(2);
        expect($output['checks']['database']['connection']['status'])->toBe('ok');
        expect($output['checks']['database']['extended_stats']['status'])->toBe('ok');
        expect($output['checks']['database']['session_integrity_scope']['mode'])->toBe('all_sessions');
    } finally {
        deleteDoctorTestDirectory($workspace);
    }
});

function deleteDoctorTestDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . '/' . $item;
        if (is_dir($child) && !is_link($child)) {
            deleteDoctorTestDirectory($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}