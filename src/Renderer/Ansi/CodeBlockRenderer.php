<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class CodeBlockRenderer implements NodeRendererInterface
{
    private const string DIM = "\033[2m";
    private const string RESET = "\033[0m";
    private const string LANG_STYLE = "\033[2;36m";  // dim cyan for language label

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        $code = '';
        $lang = '';

        if ($node instanceof FencedCode) {
            $code = $node->getLiteral();
            $infoWords = $node->getInfoWords();
            if ($infoWords !== [] && $infoWords[0] !== '') {
                $lang = $infoWords[0];
            }
        } elseif ($node instanceof IndentedCode) {
            $code = $node->getLiteral();
        } else {
            return '';
        }

        $code = rtrim($code, "\n");
        if (trim($code) === '') {
            return '';
        }

        $lines = explode("\n", $code);

        $output = '';

        // Language label
        if ($lang !== '') {
            $output .= self::LANG_STYLE . '  ╭─ ' . $lang . self::RESET . "\n";
        } else {
            $output .= self::DIM . '  ╭─' . self::RESET . "\n";
        }

        // Code lines with dim border
        foreach ($lines as $line) {
            $output .= self::DIM . '  │ ' . self::RESET . $line . "\n";
        }

        $output .= self::DIM . '  ╰─' . self::RESET . "\n\n";

        return $output;
    }
}
