<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

use CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory;

/**
 * Discovers and sorts files in a backstory source directory.
 *
 * Sort order:
 *   1. Numbered prefixes first (natural sort via strnatcasecmp)
 *   2. Unnumbered items alphabetically after numbered ones
 *   3. Files before subdirectories at each level
 *   4. Recursive — subdirectory contents follow the same rules
 */
final readonly class BackstoryFileDiscovery
{
    private ExtractorFactory $factory;

    public function __construct(?ExtractorFactory $factory = null)
    {
        $this->factory = $factory ?? new ExtractorFactory();
    }

    /**
     * Discover all supported files recursively, returned in sorted order.
     *
     * @return list<BackstoryFileEntry>
     */
    public function discover(string $backstoryDir): array
    {
        if (!is_dir($backstoryDir)) {
            return [];
        }

        $entries = [];
        $this->scanDirectory($backstoryDir, $backstoryDir, $entries);

        return $entries;
    }

    /**
     * @param list<BackstoryFileEntry> $entries Accumulator
     */
    private function scanDirectory(string $rootDir, string $currentDir, array &$entries): void
    {
        $items = scandir($currentDir);
        if ($items === false) {
            return;
        }

        // Separate files and directories, filtering out dots and hidden files
        $files = [];
        $dirs = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }

            $fullPath = $currentDir . '/' . $item;

            if (is_file($fullPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if ($this->factory->isSupported($ext)) {
                    $files[] = $item;
                }
            } elseif (is_dir($fullPath)) {
                $dirs[] = $item;
            }
        }

        // Sort files: numbered first (natural sort), then unnumbered (alpha)
        usort($files, self::numberedFirstComparator(...));

        // Add files at this level first
        foreach ($files as $file) {
            $absolutePath = $currentDir . '/' . $file;
            $relativePath = ltrim(substr($absolutePath, strlen($rootDir)), '/');
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            $entries[] = new BackstoryFileEntry(
                relativePath: $relativePath,
                absolutePath: $absolutePath,
                extension: $ext,
            );
        }

        // Sort directories: numbered first, then unnumbered
        usort($dirs, self::numberedFirstComparator(...));

        // Recurse into subdirectories
        foreach ($dirs as $dir) {
            $this->scanDirectory($rootDir, $currentDir . '/' . $dir, $entries);
        }
    }

    /**
     * Comparator: items with a leading numeric prefix sort first (natural order),
     * then non-numeric items sort alphabetically.
     */
    private static function numberedFirstComparator(string $a, string $b): int
    {
        $aNum = self::hasNumericPrefix($a);
        $bNum = self::hasNumericPrefix($b);

        if ($aNum && $bNum) {
            return strnatcasecmp($a, $b);
        }

        if ($aNum) {
            return -1;
        }

        if ($bNum) {
            return 1;
        }

        return strnatcasecmp($a, $b);
    }

    private static function hasNumericPrefix(string $name): bool
    {
        return $name !== '' && ctype_digit($name[0]);
    }
}
