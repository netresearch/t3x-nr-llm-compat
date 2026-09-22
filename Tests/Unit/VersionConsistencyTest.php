<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit;

use Composer\Semver\Semver;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The nr-llm floor, read from the two files the two install paths read:
 * composer.json for a Composer installation, ext_emconf.php for the
 * extension manager.
 *
 * The floor is 0.35.0 because `CreateNewsDraftTool` hands a `WriteKind` to
 * `ToolResult::withWriteTarget()`, and nr-llm 0.35.0 is where both exist:
 * 0.34 has neither the enum nor the second parameter. On 0.34 nothing fails
 * early — the tool loads, the contract verifies, the integration activates,
 * the hidden record is created — and the success line then fatals on the
 * missing class. Only the constraint keeps 0.34 out; nothing at runtime
 * checks it.
 */
#[CoversNothing]
final class VersionConsistencyTest extends UnitTestCase
{
    private const NR_LLM_FLOOR = '0.35.0';

    /** The highest release without `WriteKind`. */
    private const LAST_BELOW_FLOOR = '0.34.99';

    #[Test]
    public function composerJsonAdmitsTheFloorAndNothingBelowIt(): void
    {
        $constraint = $this->composerRequirement('netresearch/nr-llm');

        self::assertFalse(
            Semver::satisfies(self::LAST_BELOW_FLOOR, $constraint),
            'composer.json admits nr-llm ' . self::LAST_BELOW_FLOOR . ' ("' . $constraint
            . '"), which has no WriteKind for the news tool to pass to withWriteTarget().',
        );
        self::assertTrue(
            Semver::satisfies(self::NR_LLM_FLOOR, $constraint),
            'composer.json does not admit nr-llm ' . self::NR_LLM_FLOOR . ' ("' . $constraint . '").',
        );
    }

    #[Test]
    public function extEmconfDeclaresTheSameFloorAndStaysInsideComposersRange(): void
    {
        $emconf = file_get_contents($this->repoRoot() . '/ext_emconf.php');
        self::assertIsString($emconf, 'ext_emconf.php must be readable');
        self::assertSame(
            1,
            preg_match("/'nr_llm'\\s*=>\\s*'([^'-]+)-([^']+)'/", $emconf, $matches),
            'ext_emconf.php must declare an nr_llm constraint in the min-max form',
        );

        self::assertSame(
            self::NR_LLM_FLOOR,
            $matches[1],
            'ext_emconf.php declares a different nr_llm floor than composer.json.',
        );

        $constraint = $this->composerRequirement('netresearch/nr-llm');
        self::assertTrue(
            Semver::satisfies($matches[2], $constraint),
            'ext_emconf.php admits nr_llm ' . $matches[2] . ', which composer.json ("' . $constraint . '") does not.',
        );
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function composerRequirement(string $package): string
    {
        $contents = file_get_contents($this->repoRoot() . '/composer.json');
        self::assertIsString($contents, 'composer.json must be readable');

        $composer = json_decode($contents, true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);

        $constraint = $composer['require'][$package] ?? null;
        self::assertIsString($constraint, 'composer.json must require ' . $package);

        return $constraint;
    }
}
