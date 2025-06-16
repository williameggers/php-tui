<?php

declare(strict_types=1);

namespace PhpTui\Tui\Text;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\Color;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;

/**
 * This is a subset of the Symfony Console markup. It parses strings into a list of spans.
 *
 * @see https://symfony.com/doc/current/console/coloring.html
 */
final class SpanParser
{
    private const OPEN_TAG_REGEX = '[a-z](?:[^\\\\<>]*+ | \\\\.)*';
    private const CLOSE_TAG_REGEX = '[a-z][^<>]*+';

    public static function new(): self
    {
        return new self();
    }

    /**
     * @return array<Span>
     */
    public function parse(string $input): array
    {
        $regex = sprintf('#<((%s) | /(%s)?)>#ix', self::OPEN_TAG_REGEX, self::CLOSE_TAG_REGEX);
        preg_match_all($regex, $input, $matches, PREG_OFFSET_CAPTURE);

        $spans = [];
        $offset = 0;
        $styleStack = [];
        foreach ($matches[0] as $index => $match) {
            $tag = $match[0];
            $pos = $match[1];

            if ($this->isEscapedTag($input, $pos)) {
                continue;
            }

            $textBeforeTag = substr($input, $offset, $pos - $offset);
            if ($textBeforeTag !== '') {
                $spans[] = $this->createSpan($textBeforeTag, $styleStack);
            }

            $offset = $pos + strlen($tag);

            $isOpeningTag = $tag[1] !== '/';
            if ($isOpeningTag) {
                $tagAttributes = $matches[1][$index][0];
                $styleStack[] = $this->createStyleFromTag($tagAttributes, $styleStack);
            } else {
                array_pop($styleStack);
            }
        }

        if ($offset < strlen($input)) {
            $spans[] = $this->createSpan(substr($input, $offset), $styleStack);
        }

        return $spans;
    }

    private function isEscapedTag(string $input, int $pos): bool
    {
        return $pos !== 0 && '\\' === $input[$pos - 1];
    }

    /**
     * @param array<Style> $styleStack
     */
    private function createStyleFromTag(string $tag, array &$styleStack): Style
    {
        $style = Style::default();

        $attributes = explode(';', $tag);
        foreach ($attributes as $attribute) {
            $attribute = explode('=', $attribute);
            [$key, $value] = [
                $attribute[0] ?? '',
                $attribute[1] ?? '',
            ];
            if ($key === '' || $value === '') {
                continue;
            }
            $style = $this->updateStyleWithAttribute($style, $key, $value);
        }

        // Apply the style of the outermost tag with modifications from the current tag.
        if ($styleStack !== []) {
            $style = end($styleStack)->patchStyle($style);
        }

        return $style;
    }

    private function updateStyleWithAttribute(Style $style, string $attribute, string $value): Style
    {
        if ($attribute === 'fg') {
            return $style->fg($this->parseColor($value));
        }

        if ($attribute === 'bg') {
            return $style->bg($this->parseColor($value));
        }

        if ($attribute === 'options') {
            $options = explode(',', $value);
            foreach ($options as $option) {
                $isRemove = str_starts_with($option, '~');
                $name = $isRemove ? mb_substr($option, 1) : $option;
                $modifier = Modifier::fromName($name);

                if ($isRemove) {
                    $style->removeModifier($modifier);
                } else {
                    $style->addModifier($modifier);
                }
            }
        }

        return $style;
    }

    private function parseColor(string $color): Color
    {
        if (str_starts_with($color, '#')) {
            return RgbColor::fromHex($color);
        }

        return AnsiColor::fromName($color);
    }

    /**
     * @param array<Style> $styleStack
     */
    private function createSpan(string $text, array &$styleStack): Span
    {
        $text = strtr($text, ["\0" => '\\', '\\<' => '<', '\\>' => '>']);

        $style = $styleStack === [] ? Style::default() : end($styleStack);

        return Span::styled($text, $style);
    }
}
