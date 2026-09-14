<?php

declare(strict_types=1);

namespace App\Modules\Admin\Database\Factories;

use App\Modules\Admin\Enums\ContentPageStatus;
use App\Modules\Admin\Models\ContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentPage>
 */
class ContentPageFactory extends Factory
{
    protected $model = ContentPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'status' => ContentPageStatus::Draft,
            'is_system' => false,
            'show_in_footer' => true,
            'position' => fake()->numberBetween(0, 20),
            'meta_description' => fake()->sentence(),
        ];
    }

    /**
     * A page with one version behind it, live at its slug.
     */
    public function published(string $body = 'The published body.'): static
    {
        return $this->afterCreating(function (ContentPage $page) use ($body): void {
            $version = $page->versions()->create([
                'version' => 1,
                'title' => $page->title,
                'body' => $body,
                'change_note' => 'First publication.',
                'published_at' => now(),
            ]);

            $page->forceFill([
                'status' => ContentPageStatus::Published,
                'current_version_id' => $version->id,
                'published_at' => now(),
            ])->save();
        });
    }

    /**
     * The platform terms page, which checkout quotes.
     */
    public function platformTerms(): static
    {
        return $this->state([
            'slug' => ContentPage::TERMS_SLUG,
            'title' => 'Platform terms of use',
            'is_system' => true,
        ]);
    }
}
