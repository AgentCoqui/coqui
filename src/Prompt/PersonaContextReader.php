<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Prompt;

/**
 * Reads a persona's supplementary context notes (context/*.md) into one
 * pinned markdown block. Persona-owned: no workspace/default fallback.
 */
final readonly class PersonaContextReader
{
    public function read(string $personaPath, string $heading = 'Context'): ?string
    {
        $dir = rtrim($personaPath, '/') . '/context';
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.md') ?: [];
        $this->naturalSort($files);

        $parts = [];
        foreach ($files as $file) {
            $body = file_get_contents($file);
            if ($body === false || trim($body) === '') {
                continue;
            }
            $title = pathinfo($file, PATHINFO_FILENAME);
            $parts[] = "### {$title}\n\n" . trim($body);
        }

        if ($parts === []) {
            return null;
        }

        return "## {$heading}\n\n" . implode("\n\n", $parts);
    }

    /**
     * Numbered-prefixed files first (natural order), then the rest alphabetically.
     *
     * @param list<string> $files
     */
    private function naturalSort(array &$files): void
    {
        usort($files, static function (string $a, string $b): int {
            $an = pathinfo($a, PATHINFO_FILENAME);
            $bn = pathinfo($b, PATHINFO_FILENAME);
            $aNum = preg_match('/^\d+/', $an) === 1;
            $bNum = preg_match('/^\d+/', $bn) === 1;
            if ($aNum !== $bNum) {
                return $aNum ? -1 : 1;
            }
            return strnatcasecmp($an, $bn);
        });
    }
}
