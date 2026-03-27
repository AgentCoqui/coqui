<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;

final class AnsiRendererExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Register parsers from upstream extensions
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new TaskListExtension());

        // Override all renderers with ANSI versions (priority 10 beats the default 0)
        $environment
            // Block-level
            ->addRenderer(Document::class,     new DocumentRenderer(),          10)
            ->addRenderer(Heading::class,      new HeadingRenderer(),           10)
            ->addRenderer(Paragraph::class,    new ParagraphRenderer(),         10)
            ->addRenderer(FencedCode::class,   new CodeBlockRenderer(),         10)
            ->addRenderer(IndentedCode::class, new CodeBlockRenderer(),         10)
            ->addRenderer(BlockQuote::class,   new BlockQuoteRenderer(),        10)
            ->addRenderer(ListBlock::class,    new ListBlockRenderer(),         10)
            ->addRenderer(ListItem::class,     new ListItemRenderer(),          10)
            ->addRenderer(ThematicBreak::class, new ThematicBreakRenderer(),    10)
            ->addRenderer(HtmlBlock::class,    new HtmlBlockRenderer(),         10)
            ->addRenderer(Table::class,        new TableRenderer(),             10)

            // Inline
            ->addRenderer(Text::class,         new TextRenderer(),              10)
            ->addRenderer(Strong::class,       new StrongRenderer(),            10)
            ->addRenderer(Emphasis::class,     new EmphasisRenderer(),          10)
            ->addRenderer(Code::class,         new InlineCodeRenderer(),        10)
            ->addRenderer(Link::class,         new LinkRenderer(),              10)
            ->addRenderer(Image::class,        new ImageRenderer(),             10)
            ->addRenderer(Newline::class,      new NewlineRenderer(),           10)
            ->addRenderer(HtmlInline::class,   new HtmlInlineRenderer(),        10)
            ->addRenderer(Strikethrough::class, new StrikethroughRenderer(),    10)
            ->addRenderer(TaskListItemMarker::class, new TaskListItemMarkerRenderer(), 10)
        ;
    }
}
