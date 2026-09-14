<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Privacy\Support\PersonalDataSection;
use App\Support\Pdf\PdfDocument;
use JsonException;

/**
 * Assembles everything MonaFind holds about one person.
 *
 * The Act entitles a data subject to a copy of their data in a structured,
 * commonly used, machine-readable form — and, separately, to understand it.
 * Those pull in different directions, so this produces both: JSON for the
 * first, a PDF of the same content for the second.
 *
 * ## It knows nothing about any module
 *
 * Every section comes from a `PersonalDataSource` that the owning module
 * registered. Adding a module with personal data in it means writing that
 * module's source; nothing here changes. Nothing here can go stale against a
 * schema it cannot see.
 *
 * ## Empty sections are dropped
 *
 * A buyer who has never raised a dispute should not receive a heading called
 * "Disputes" with nothing under it — it reads like something is missing.
 */
class PersonalDataExporter
{
    public function __construct(private readonly PersonalDataRegistry $registry) {}

    /**
     * The whole export as a nested array: the envelope, then one entry per
     * module that had something.
     *
     * @return array{
     *     subject: array<string, mixed>,
     *     generated_at: string,
     *     sections: array<string, array<int, array{title: string, note: string|null, rows: array<int, array<string, scalar|null>>}>>
     * }
     */
    public function assemble(User $user): array
    {
        $sections = [];

        foreach ($this->registry->all() as $source) {
            $contributed = array_values(array_filter(
                $source->export($user),
                static fn (PersonalDataSection $section): bool => ! $section->isEmpty(),
            ));

            if ($contributed === []) {
                continue;
            }

            $sections[$source->key()] = array_map(
                static fn (PersonalDataSection $section): array => $section->toArray(),
                $contributed,
            );
        }

        return [
            'subject' => [
                'account_id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'generated_at' => now()->toIso8601String(),
            'sections' => $sections,
        ];
    }

    /**
     * The machine-readable copy.
     *
     * Pretty-printed and with slashes left alone, because a person opening
     * this in a text editor is a supported way to read it. JSON_THROW_ON_ERROR
     * rather than a silent `false`: an export that quietly produced an empty
     * file would be worse than one that failed.
     *
     * @throws JsonException
     */
    public function toJson(User $user): string
    {
        return json_encode(
            $this->assemble($user),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The readable copy.
     *
     * Built on the same small PDF writer the receipts use — see
     * App\Support\Pdf\PdfDocument for why there is no rendering library here.
     * Wide tables are printed as label/value pairs rather than squeezed into
     * columns, because an unreadable table serves the person no better than
     * no table at all.
     */
    public function toPdf(User $user): string
    {
        $export = $this->assemble($user);

        $pdf = new PdfDocument;
        $pdf->addPage();

        $y = 60.0;
        $pdf->text(48, $y, 'Your MonaFind data', 20, true);
        $y += 24;
        $pdf->text(48, $y, sprintf('Account %d — %s', $user->getKey(), $user->name), 10);
        $y += 14;
        $pdf->text(48, $y, 'Generated '.now()->timezone(config('monafind.display_timezone'))->format('j F Y, H:i'), 9);
        $y += 10;
        $pdf->line(48, $y, $pdf->width() - 48, $y);
        $y += 24;

        foreach ($export['sections'] as $sections) {
            foreach ($sections as $section) {
                $y = $this->writeSection($pdf, $section, $y);
            }
        }

        return $pdf->render();
    }

    /**
     * Write one section, starting a new page whenever the next block would
     * run off the bottom.
     *
     * @param  array{title: string, note: string|null, rows: array<int, array<string, scalar|null>>}  $section
     */
    private function writeSection(PdfDocument $pdf, array $section, float $y): float
    {
        $y = $this->ensureRoom($pdf, $y, 60);

        $pdf->text(48, $y, $section['title'], 13, true);
        $y += 16;

        if ($section['note'] !== null) {
            foreach ($pdf->wrap($section['note'], $pdf->width() - 96, 9) as $line) {
                $pdf->text(48, $y, $line, 9);
                $y += 12;
            }
        }

        $y += 4;

        foreach ($section['rows'] as $index => $row) {
            $y = $this->ensureRoom($pdf, $y, 40);

            if ($index > 0) {
                $pdf->line(48, $y - 6, $pdf->width() - 48, $y - 6, 0.3, 0.9);
            }

            foreach ($row as $label => $value) {
                $y = $this->ensureRoom($pdf, $y, 20);

                $pdf->text(56, $y, (string) $label, 9, true);

                $text = $this->stringify($value);

                foreach ($pdf->wrap($text, $pdf->width() - 260, 9) as $offset => $line) {
                    $pdf->text(200, $y + ($offset * 11), $line, 9);
                }

                $y += 11 * max(1, count($pdf->wrap($text, $pdf->width() - 260, 9)));
            }

            $y += 8;
        }

        return $y + 14;
    }

    private function ensureRoom(PdfDocument $pdf, float $y, float $needed): float
    {
        if ($y + $needed <= $pdf->height() - 56) {
            return $y;
        }

        $pdf->addPage();

        return 60.0;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }
}
