<?php

declare(strict_types=1);

namespace App\Modules\Admin\Database\Factories;

use App\Modules\Admin\Models\ContentPage;
use App\Modules\Admin\Models\ContentPageVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPageVersion>
 */
class ContentPageVersionFactory extends Factory
{
    protected $model = ContentPageVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_page_id' => ContentPage::factory(),
            'version' => 1,
            'title' => fake()->sentence(3),
            'body' => fake()->paragraphs(3, true),
            'change_note' => fake()->sentence(),
            'created_by_label' => fake()->name(),
            'published_at' => now(),
        ];
    }
}
