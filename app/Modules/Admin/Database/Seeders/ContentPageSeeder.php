<?php

declare(strict_types=1);

namespace App\Modules\Admin\Database\Seeders;

use App\Modules\Admin\Enums\ContentPageStatus;
use App\Modules\Admin\Models\ContentPage;
use Illuminate\Database\Seeder;

/**
 * The five pages the brief names: About, FAQ, Contact, Terms, Privacy.
 *
 * Re-running this is safe and deliberately conservative: a page that already
 * exists keeps every word staff have written on it. Only a page that has
 * never been published gets the text below, so a deployment cannot quietly
 * restore the platform's terms to the developer's draft.
 *
 * The terms page seeds from the value already in `policies.platform_terms_body`
 * so that an installation which has been running on the settings-only version
 * carries its existing wording — and its version number — into the CMS rather
 * than starting again at 1 and orphaning past acceptances.
 */
class ContentPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $definition) {
            $page = ContentPage::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                [
                    'title' => $definition['title'],
                    'status' => ContentPageStatus::Draft,
                    'is_system' => $definition['is_system'],
                    'show_in_footer' => true,
                    'position' => $definition['position'],
                    'meta_description' => $definition['meta_description'],
                ],
            );

            if ($page->current_version_id !== null) {
                continue;
            }

            $version = $page->versions()->create([
                'version' => $this->initialVersionFor($page),
                'title' => $definition['title'],
                'body' => $this->bodyFor($page, $definition['body']),
                'change_note' => 'Seeded with the platform defaults.',
                'published_at' => now(),
            ]);

            $page->forceFill([
                'status' => ContentPageStatus::Published,
                'current_version_id' => $version->id,
                'published_at' => now(),
            ])->save();
        }
    }

    /**
     * The terms page's words come from the setting that already holds them.
     *
     * That setting is what the checkout modal has been rendering all along,
     * so taking it verbatim is what makes the CMS version agree with what
     * buyers were actually shown.
     */
    private function bodyFor(ContentPage $page, string $default): string
    {
        if (! $page->isPlatformTerms()) {
            return $default;
        }

        $stored = (string) settings('policies.platform_terms_body', '');

        return $stored !== '' ? $stored : $default;
    }

    /**
     * The terms page starts at whatever version the settings already claim.
     *
     * Every TermsAcceptance row written so far points at that number. Starting
     * this page at 1 would make those rows reference a version whose text the
     * CMS says is something else.
     */
    private function initialVersionFor(ContentPage $page): int
    {
        if (! $page->isPlatformTerms()) {
            return 1;
        }

        return max(1, (int) settings('policies.platform_terms_version', '1'));
    }

    /**
     * @return array<int, array{slug: string, title: string, is_system: bool, position: int, meta_description: string, body: string}>
     */
    private function pages(): array
    {
        return [
            [
                'slug' => 'about',
                'title' => 'About MonaFindAuto',
                'is_system' => false,
                'position' => 10,
                'meta_description' => 'MonaFindAuto connects Zambian buyers with verified sellers of vehicle parts.',
                'body' => <<<'TEXT'
                MonaFindAuto is a Zambian marketplace for vehicle parts.

                Finding the right part in Zambia usually means phoning around Kamwala,
                Chachacha Road and half a dozen yards, and hearing "come and see"
                from all of them. MonaFind puts the stock on one screen: what each
                shop actually has, what it costs including VAT, and how far away it is.

                Every seller on the platform has been verified by our staff before
                they can trade. Parts we have inspected ourselves carry an Inspected
                badge, and it is kept separate from whether the part is new, used or
                from a breaker's yard — those are two different questions and we do
                not merge them.

                Payment is held by MonaFind until you confirm the part is right,
                unless the seller's page says otherwise. If it is wrong or damaged,
                you are covered whatever the seller's own policy says.
                TEXT,
            ],
            [
                'slug' => 'faq',
                'title' => 'Frequently asked questions',
                'is_system' => false,
                'position' => 20,
                'meta_description' => 'How buying, paying and returning parts works on MonaFindAuto.',
                'body' => <<<'TEXT'
                **Do I need an account to look?**
                No. You can browse every listing, every shop and every price without
                signing in. You need an account to see a seller's phone number and to
                buy, because contact details are not something we hand to anybody who
                asks.

                **When does the seller get my money?**
                Not immediately. For most sellers MonaFind holds the payment until you
                confirm you have the part, or until the checking window closes. The
                window is shown on the order.

                **The part is wrong. What now?**
                Open a dispute from the order before it completes. Money stays where it
                is until MonaFind decides it.

                **Are the prices VAT-inclusive?**
                Yes. The price shown is the price you pay.

                **What does "Unconfirmed" mean on a listing?**
                The seller has not confirmed in the last few days that the part is still
                on the shelf. It may well be — but ring before you travel.
                TEXT,
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact MonaFind',
                'is_system' => false,
                'position' => 30,
                'meta_description' => 'How to reach MonaFindAuto about an order, a shop or your account.',
                'body' => <<<'TEXT'
                **About an order or a dispute**
                Use the order itself. Opening a dispute from the order puts it in front
                of the staff who can actually move the money, with the whole history
                attached — an email to us has neither.

                **About a shop or a listing**
                Every listing and every seller page has a Report link.

                **Everything else**
                support@monafindauto.zm

                We answer during Zambian business hours, Monday to Friday.
                TEXT,
            ],
            [
                'slug' => ContentPage::TERMS_SLUG,
                'title' => 'Platform terms of use',
                'is_system' => true,
                'position' => 40,
                'meta_description' => 'The terms under which MonaFindAuto operates its marketplace.',
                /* Filled from policies.platform_terms_body — see bodyFor(). */
                'body' => 'MonaFindAuto is a marketplace. Each part is sold by the seller listed against it.',
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy notice',
                'is_system' => true,
                'position' => 50,
                'meta_description' => 'What MonaFindAuto collects, why, and who sees it.',
                'body' => <<<'TEXT'
                **What we hold**
                Your name, phone number and email address; the addresses you save for
                delivery; your orders and the messages attached to them. Sellers give us
                their business registration and premises details as well, which our
                staff read during verification and nobody else ever sees.

                **Card details**
                We never hold them. Payments go through Lenco's own secure widget and
                card numbers do not touch MonaFind's servers.

                **Who sees your contact details**
                A seller you have ordered from, so they can arrange the part with you.
                Not other buyers, and not guests browsing the site.

                **Where it lives**
                On servers we control. Payout bank details are encrypted at rest.

                **What we never do**
                Sell your details to anybody.
                TEXT,
            ],
        ];
    }
}
