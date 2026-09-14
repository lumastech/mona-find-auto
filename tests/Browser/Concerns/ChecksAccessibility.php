<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Runs axe-core against the page a Dusk browser is on.
 *
 * ## Why the script is injected rather than loaded from a CDN
 *
 * A CI gate that depends on somebody else's network is a CI gate that goes
 * red on a morning when nothing is wrong. `axe-core` is a dev dependency and
 * the file is read off disk and evaluated in the page.
 *
 * ## Why it polls instead of using an async script
 *
 * `axe.run()` returns a promise. Dusk's `script()` is `executeScript`, which
 * is synchronous and would hand back `undefined` before axe had finished. So
 * the run parks its result on `window.__axeResult` and this waits for it —
 * ugly, and the alternative is a result that is silently always empty, which
 * is the worst possible outcome for a test whose job is to find problems.
 *
 * ## What it checks
 *
 * The `wcag2a`, `wcag2aa`, `wcag21a` and `wcag21aa` rule tags — the standard
 * the brief commits the storefront to, and nothing beyond it. Best-practice
 * rules are deliberately excluded: they are opinions, and a gate that fails
 * on an opinion gets switched off.
 */
trait ChecksAccessibility
{
    /**
     * Assert the current page has no WCAG 2.1 AA violations.
     *
     * `$context` narrows the scan to a selector — used where a page embeds a
     * third-party iframe we do not control and cannot fix.
     */
    public function assertAccessible(Browser $browser, string $label, ?string $context = null): void
    {
        $violations = $this->axeViolations($browser, $context);

        Assert::assertSame(
            [],
            $violations,
            sprintf("%s has %d accessibility violation(s):\n\n%s", $label, count($violations), $this->describe($violations)),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function axeViolations(Browser $browser, ?string $context = null): array
    {
        $browser->script($this->axeSource());

        $target = $context === null ? 'document' : json_encode($context);

        $browser->script(<<<JS
            window.__axeResult = null;
            window.__axeError = null;

            axe.run({$target}, {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
            })
                .then((result) => { window.__axeResult = result; })
                .catch((error) => { window.__axeError = String(error); });
        JS);

        $browser->waitUsing(20, 100, static function () use ($browser): bool {
            return (bool) $browser->script('return window.__axeResult !== null || window.__axeError !== null;')[0];
        }, 'axe-core did not finish');

        $error = $browser->script('return window.__axeError;')[0];

        if (is_string($error)) {
            throw new RuntimeException('axe-core failed: '.$error);
        }

        /** @var array<string, mixed> $result */
        $result = $browser->script('return window.__axeResult;')[0];

        /** @var array<int, array<string, mixed>> $violations */
        $violations = $result['violations'] ?? [];

        return $violations;
    }

    /**
     * The axe-core bundle, read off disk.
     */
    protected function axeSource(): string
    {
        $path = base_path('node_modules/axe-core/axe.min.js');

        if (! is_file($path)) {
            throw new RuntimeException(
                'axe-core is not installed. Run `npm ci` before the accessibility suite.',
            );
        }

        return (string) file_get_contents($path);
    }

    /**
     * A failure message somebody can act on without opening a browser.
     *
     * axe's own output is a deep structure; what a developer needs is the
     * rule, the impact, the selector and the URL of the rule's explanation.
     *
     * @param  array<int, array<string, mixed>>  $violations
     */
    protected function describe(array $violations): string
    {
        $lines = [];

        foreach ($violations as $violation) {
            $lines[] = sprintf(
                "  [%s] %s — %s\n    %s",
                $violation['impact'] ?? 'unknown',
                $violation['id'] ?? '?',
                $violation['help'] ?? '',
                $violation['helpUrl'] ?? '',
            );

            foreach (($violation['nodes'] ?? []) as $node) {
                $selector = $node['target'][0] ?? '?';
                $lines[] = sprintf('    at %s', is_array($selector) ? implode(' ', $selector) : $selector);

                if (! empty($node['failureSummary'])) {
                    $lines[] = '      '.str_replace("\n", "\n      ", (string) $node['failureSummary']);
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
