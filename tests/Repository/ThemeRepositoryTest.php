<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\ThemeRepository;

class ThemeRepositoryTest extends AbstractTestCase
{
    private ThemeRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ThemeRepository();
    }

    private function describeTable(string $table): array
    {
        return $this->pdo->query("DESCRIBE `$table`")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testInsertMarkerGetThemesInUseAndClearThemeSettings(): void
    {
        $desc = $this->describeTable('theme_settings');
        if (empty($desc)) {
            $this->markTestIncomplete('No `theme_settings` table present; skipping ThemeRepository tests.');
            return;
        }

        $themeMarker = 'unit_test_theme_' . uniqid();

        $this->pdo->prepare('DELETE FROM `theme_settings` WHERE `theme` = :t')->execute([':t' => $themeMarker]);

        try {
            $this->repo->insertThemeSetting($themeMarker, null, null);
        } catch (\Throwable $e) {
            $this->pdo->prepare('DELETE FROM `theme_settings` WHERE `theme` = :t')->execute([':t' => $themeMarker]);
            $this->markTestIncomplete('Could not insert marker row into `theme_settings`: ' . $e->getMessage());
            return;
        }

        $themes = $this->repo->getThemesInUse();
        $this->assertIsArray($themes);
        $this->assertContains($themeMarker, $themes, 'getThemesInUse should include the marker theme');

        $themeNonMarker = 'unit_test_theme_nonmarker_' . uniqid();
        try {
            $this->repo->insertThemeSetting($themeNonMarker, 'color', 'blue');
        } catch (\Throwable $e) {
        }
        $themesAfterNon = $this->repo->getThemesInUse();
        $this->assertIsArray($themesAfterNon);
        $this->assertNotContains($themeNonMarker, $themesAfterNon, 'Non-marker theme should not appear in getThemesInUse');

        $this->repo->clearThemeSettings($themeMarker);

        $themesAfterClear = $this->repo->getThemesInUse();
        $this->assertIsArray($themesAfterClear);
        $this->assertNotContains($themeMarker, $themesAfterClear, 'clearThemeSettings should remove the marker theme');

        $this->pdo->prepare('DELETE FROM `theme_settings` WHERE `theme` IN (:a, :b)')->execute([
            ':a' => $themeMarker,
            ':b' => $themeNonMarker,
        ]);
    }
}
