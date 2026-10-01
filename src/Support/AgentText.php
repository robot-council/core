<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Delimiter\Processor\EmphasisDelimiterProcessor;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\CommonMark\Parser\Block\ListBlockStartParser;
use League\CommonMark\Extension\CommonMark\Parser\Inline\AutolinkParser;
use League\CommonMark\Extension\CommonMark\Parser\Inline\BacktickParser;
use League\CommonMark\Extension\CommonMark\Parser\Inline\CloseBracketParser;
use League\CommonMark\Extension\CommonMark\Parser\Inline\EscapableParser;
use League\CommonMark\Extension\CommonMark\Parser\Inline\OpenBracketParser;
use League\CommonMark\Extension\ConfigurableExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\Inline\NewlineParser;
use League\CommonMark\Parser\MarkdownParser;
use League\Config\ConfigurationBuilderInterface;
use Nette\Schema\Expect;

/**
 * The safe Markdown subset agent-written prose is shown in on the dashboard (#537).
 *
 * **Any agent in the fleet writes this text, so the subset is defined by what it refuses.** It is
 * parsed by CommonMark with only these parsers registered: paragraphs, bulleted and numbered lists
 * (block form only), code spans, emphasis and strong emphasis, backslash escapes, line breaks, and
 * links. Every other construct is never recognized, so it stays the characters it was written as:
 * raw HTML, images, headings, block quotes, fenced and indented code, thematic breaks and entities.
 *
 * **No HTML is built here.** The result is a tree of plain arrays whose strings are rendered by
 * `components/agent-text.blade.php` through `{{ }}`, so every character an agent wrote is escaped
 * by Blade, and `EscapingGuardTest`'s rule that the package never hands Blade an `Htmlable` holds.
 *
 * **A link is a link only when it names a GitHub issue or pull request.** `TicketLink::reference()`
 * reads the reference out of the URL, and the view renders it through `external-link`, which
 * rebuilds the URL itself, so no URL an agent wrote reaches an `href`. Any other link is shown as
 * the text that was written: `[label](url)`, or the bare address of an autolink.
 *
 * The configuration CommonMark itself takes -- `html_input: escape`, `allow_unsafe_links: false`,
 * and bounds on nesting and delimiters -- is set as well, though no renderer of CommonMark's runs,
 * so a later change that reaches for one starts from the safe settings.
 *
 * Node shapes: `text` and `code` carry `text`; `emphasis`, `strong` and `paragraph` carry
 * `children`; `link` carries `reference` and `children`; `list` carries `ordered`, `start` and
 * `items`, each a list of nodes; `break` carries nothing.
 */
final class AgentText
{
    /**
     * How deep emphasis, links and lists may nest before the rest is shown as plain text, which
     * bounds how deep the view's recursion can go whatever a 4,000-character body contains.
     */
    public const int MAX_DEPTH = 8;

    /** @var array<string, MarkdownParser> By mode. */
    private static array $parsers = [];

    /**
     * The text as paragraphs and lists: for a field that holds prose of any length.
     *
     * @param  string|null  $text  What an agent wrote.
     * @return list<array<string, mixed>> The nodes.
     */
    public static function blocks(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        return self::children(self::parser('blocks')->parse($text), 0);
    }

    /**
     * The text as one run of inline nodes: for a field shown inside a line, where a list or a
     * paragraph would break the markup around it. A list marker stays text, and paragraphs are
     * joined by a line break.
     *
     * @param  string|null  $text  What an agent wrote.
     * @return list<array<string, mixed>> The nodes.
     */
    public static function inline(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        $nodes = [];

        foreach (self::children(self::parser('inline')->parse($text), 0) as $paragraph) {
            if ($nodes !== []) {
                $nodes[] = ['type' => 'break'];
            }

            $children = $paragraph['children'] ?? [];

            if (\is_array($children)) {
                foreach ($children as $child) {
                    if (\is_array($child)) {
                        $nodes[] = $child;
                    }
                }
            }
        }

        /** @var list<array<string, mixed>> $nodes */
        return $nodes;
    }

    /**
     * A parser for one mode, built once.
     *
     * @param  'blocks'|'inline'  $mode  Whether lists are recognized.
     */
    private static function parser(string $mode): MarkdownParser
    {
        if (! isset(self::$parsers[$mode])) {
            $environment = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'max_nesting_level' => self::MAX_DEPTH,
                'max_delimiters_per_line' => 200,
            ]);

            $environment->addExtension(new readonly class($mode === 'blocks') implements ConfigurableExtensionInterface
            {
                public function __construct(private bool $lists) {}

                public function configureSchema(ConfigurationBuilderInterface $builder): void
                {
                    // The keys CommonMark's own parsers read, at CommonMark's defaults
                    $builder->addSchema('commonmark', Expect::structure([
                        'use_asterisk' => Expect::bool(true),
                        'use_underscore' => Expect::bool(true),
                        'enable_strong' => Expect::bool(true),
                        'enable_em' => Expect::bool(true),
                        'unordered_list_markers' => Expect::listOf('string')->min(1)->default(['*', '+', '-'])->mergeDefaults(false),
                    ]));
                }

                public function register(EnvironmentBuilderInterface $environment): void
                {
                    if ($this->lists) {
                        $environment->addBlockStartParser(new ListBlockStartParser, 10);
                    }

                    // No HtmlInlineParser, BangParser or EntityParser, and no block parser but
                    // lists: what is not recognized stays text
                    $environment
                        ->addInlineParser(new NewlineParser, 200)
                        ->addInlineParser(new BacktickParser, 150)
                        ->addInlineParser(new EscapableParser, 80)
                        ->addInlineParser(new AutolinkParser, 50)
                        ->addInlineParser(new CloseBracketParser, 30)
                        ->addInlineParser(new OpenBracketParser, 20)
                        ->addDelimiterProcessor(new EmphasisDelimiterProcessor('*'))
                        ->addDelimiterProcessor(new EmphasisDelimiterProcessor('_'));
                }
            });

            self::$parsers[$mode] = new MarkdownParser($environment);
        }

        return self::$parsers[$mode];
    }

    /**
     * A node's children as nodes, adjacent text merged.
     *
     * @return list<array<string, mixed>>
     */
    private static function children(Node $parent, int $depth, bool $tight = false): array
    {
        $nodes = [];

        foreach ($parent->children() as $child) {
            foreach (self::node($child, $depth, $tight) as $node) {
                $last = array_key_last($nodes);

                if ($last !== null && $node['type'] === 'text' && $nodes[$last]['type'] === 'text'
                    && \is_string($nodes[$last]['text'] ?? null) && \is_string($node['text'] ?? null)) {
                    $nodes[$last]['text'] .= $node['text'];

                    continue;
                }

                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * One CommonMark node as zero or more nodes of ours.
     *
     * @param  bool  $tight  Whether a paragraph is an item of a tight list, shown without one.
     * @return list<array<string, mixed>>
     */
    private static function node(Node $node, int $depth, bool $tight): array
    {
        if ($depth >= self::MAX_DEPTH && ! $node instanceof Text && ! $node instanceof Code && ! $node instanceof Newline) {
            return [['type' => 'text', 'text' => self::plain($node)]];
        }

        return match (true) {
            $node instanceof Text => [['type' => 'text', 'text' => $node->getLiteral()]],
            $node instanceof Code => [['type' => 'code', 'text' => $node->getLiteral()]],
            $node instanceof Newline => [$node->getType() === Newline::HARDBREAK ? ['type' => 'break'] : ['type' => 'text', 'text' => ' ']],
            $node instanceof Emphasis => [['type' => 'emphasis', 'children' => self::children($node, $depth + 1)]],
            $node instanceof Strong => [['type' => 'strong', 'children' => self::children($node, $depth + 1)]],
            $node instanceof Link => self::link($node, $depth),
            $node instanceof Paragraph => $tight ? self::children($node, $depth) : [['type' => 'paragraph', 'children' => self::children($node, $depth)]],
            $node instanceof ListBlock => [self::list($node, $depth)],
            $node instanceof ListItem => self::children($node, $depth + 1, $tight),
            default => [['type' => 'text', 'text' => self::plain($node)]],
        };
    }

    /**
     * A link: a link node when it names a GitHub issue or pull request, and the text that was
     * written otherwise.
     *
     * @return list<array<string, mixed>>
     */
    private static function link(Link $link, int $depth): array
    {
        $url = $link->getUrl();
        $children = self::children($link, $depth + 1);
        $reference = TicketLink::reference($url);

        if ($reference !== null) {
            return [['type' => 'link', 'reference' => $reference, 'children' => $children]];
        }

        // An autolink's label is its address, which is already the text that was written
        $label = self::plain($link);

        if ($label === $url || 'mailto:'.$label === $url) {
            return [['type' => 'text', 'text' => $label]];
        }

        return [['type' => 'text', 'text' => '['], ...$children, ['type' => 'text', 'text' => ']('.$url.')']];
    }

    /**
     * A list, its items each a list of nodes.
     *
     * @return array<string, mixed>
     */
    private static function list(ListBlock $list, int $depth): array
    {
        $tight = $list->isTight();
        $items = [];

        foreach ($list->children() as $item) {
            $items[] = self::node($item, $depth, $tight);
        }

        return [
            'type' => 'list',
            'ordered' => $list->getListData()->type === ListBlock::TYPE_ORDERED,
            'start' => $list->getListData()->start,
            'items' => $items,
        ];
    }

    /**
     * A node's text with every construct dropped.
     */
    private static function plain(Node $node): string
    {
        if ($node instanceof Text || $node instanceof Code) {
            return $node->getLiteral();
        }

        if ($node instanceof Newline) {
            return ' ';
        }

        $text = '';

        foreach ($node->children() as $child) {
            $text .= self::plain($child);
        }

        return $text;
    }
}
