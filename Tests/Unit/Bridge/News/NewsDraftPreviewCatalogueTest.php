<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\News;

use Netresearch\NrLlmCompat\Bridge\News\NewsDraftPreviewLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The texts of the create_news_draft approval preview.
 *
 * A line is built in PHP from a label, so a label without a text renders as
 * its own key at the approver, and a German catalogue that lags behind brings
 * back the mixed-language card the editorial guidelines forbid. Nothing but
 * this test connects the enum to the two catalogues.
 */
#[CoversClass(NewsDraftPreviewLabel::class)]
final class NewsDraftPreviewCatalogueTest extends TestCase
{
    private const PREFIX = 'approvalPreview.';

    /** What vsprintf() accepts here: `%%`, or `%s` / `%d`, optionally positional (`%1$s`). */
    private const CONVERSION = '/%%|%(?:\d+\$)?[sd]/';

    /**
     * Entries whose German text is the English one on purpose: "Teaser" is
     * the word a German editor uses for the field as well.
     */
    private const SAME_IN_BOTH = [NewsDraftPreviewLabel::Teaser];

    /**
     * @return array<string, array{NewsDraftPreviewLabel}>
     */
    public static function labels(): array
    {
        $cases = [];
        foreach (NewsDraftPreviewLabel::cases() as $label) {
            $cases[$label->name] = [$label];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('labels')]
    public function everyLabelHasAnEnglishAndAGermanText(NewsDraftPreviewLabel $label): void
    {
        self::assertNotSame('', $this->source($label), 'No English text for ' . $label->value);
        self::assertNotSame('', $this->target($label), 'No German text for ' . $label->value);
    }

    #[Test]
    #[DataProvider('labels')]
    public function theGermanTextIsAnActualTranslation(NewsDraftPreviewLabel $label): void
    {
        if (in_array($label, self::SAME_IN_BOTH, true)) {
            self::assertSame($this->source($label), $this->target($label));

            return;
        }

        self::assertNotSame($this->source($label), $this->target($label), $label->value . ' has the English text as its German one');
    }

    #[Test]
    #[DataProvider('labels')]
    public function bothTextsTakeTheSamePlaceholders(NewsDraftPreviewLabel $label): void
    {
        self::assertSame(
            $this->placeholders($this->source($label)),
            $this->placeholders($this->target($label)),
            'The placeholders of ' . $label->value . ' differ between the languages',
        );
    }

    /**
     * Every `%` in a text must be a conversion the tool fills (`%s`, `%d`,
     * optionally positional) or an escaped `%%`. A stray `%` is a conversion
     * vsprintf() does not know or one it has no value for, and it throws at
     * the moment the approver's card is built — the placeholder comparison
     * above cannot see it, because it only counts the conversions it knows.
     *
     * A text without any conversion is not passed through vsprintf() at all
     * (the tool calls it without values, and the date format goes to
     * DateTimeInterface::format()), so there `%%` would reach the card as two
     * percent signs: such a text may hold no `%` whatsoever.
     */
    #[Test]
    #[DataProvider('labels')]
    public function noTextHoldsAPercentSignThatIsNoPlaceholder(NewsDraftPreviewLabel $label): void
    {
        foreach (['source' => $this->source($label), 'target' => $this->target($label)] as $side => $text) {
            $formatted = $this->placeholders($text) !== [];

            self::assertStringNotContainsString(
                '%',
                $formatted ? (string)preg_replace(self::CONVERSION, '', $text) : $text,
                $label->value . ' (' . $side . ') has a % that is no placeholder'
                    . ($formatted ? '' : ' (a text without placeholders is shown as it is, so not even %%)')
                    . ': ' . $text,
            );
        }
    }

    #[Test]
    #[DataProvider('labels')]
    public function noTextNamesAnInternalFieldOrTool(NewsDraftPreviewLabel $label): void
    {
        // No `sys_language_uid`, `path_segment`, `create_news_draft` in what
        // the editor reads. The technical line carries identifiers by design,
        // and its table name arrives as an argument, not from here.
        foreach (['source' => $this->source($label), 'target' => $this->target($label)] as $side => $text) {
            self::assertDoesNotMatchRegularExpression(
                '/\b[a-z]+(?:_[a-z]+)+\b/',
                $text,
                $label->value . ' (' . $side . ') contains an internal name: ' . $text,
            );
        }
    }

    #[Test]
    public function everyCatalogueEntryOfThisFamilyIsALabel(): void
    {
        $known = array_map(static fn(NewsDraftPreviewLabel $label): string => $label->value, NewsDraftPreviewLabel::cases());
        sort($known);

        foreach (['locallang.xlf', 'de.locallang.xlf'] as $file) {
            self::assertSame($known, array_keys($this->units($file)), $file . ' and NewsDraftPreviewLabel disagree about the approval preview texts');
        }
    }

    private function source(NewsDraftPreviewLabel $label): string
    {
        return $this->units('locallang.xlf')[$label->value]['source'] ?? '';
    }

    private function target(NewsDraftPreviewLabel $label): string
    {
        return $this->units('de.locallang.xlf')[$label->value]['target'] ?? '';
    }

    /**
     * The placeholder conversions of a text, positions normalised away, sorted.
     *
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all(self::CONVERSION, $text, $matches);
        $conversions = array_map(
            static fn(string $match): string => substr($match, -1),
            array_values(array_filter($matches[0], static fn(string $match): bool => $match !== '%%')),
        );
        sort($conversions);

        return $conversions;
    }

    /**
     * The `approvalPreview.*` units of one catalogue, sorted by id.
     *
     * @return array<string, array{source: string, target: string}>
     */
    private function units(string $file): array
    {
        $contents = file_get_contents(__DIR__ . '/../../../../Resources/Private/Language/' . $file);
        self::assertIsString($contents);

        $units = [];
        foreach ((new SimpleXMLElement($contents))->xpath('//*[local-name()="trans-unit"]') ?? [] as $unit) {
            $id = (string)($unit['id'] ?? '');
            if (!str_starts_with($id, self::PREFIX)) {
                continue;
            }

            $units[$id] = ['source' => trim((string)$unit->source), 'target' => trim((string)$unit->target)];
        }

        ksort($units);

        return $units;
    }
}
