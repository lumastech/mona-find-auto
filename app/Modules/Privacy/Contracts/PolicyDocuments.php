<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Contracts;

/**
 * Which version of a published policy is currently in force.
 *
 * Privacy has to stamp a version number onto every consent record, and the
 * pages those numbers belong to are published by Admin. Rather than read
 * Admin's models, Privacy asks this question through an interface that Admin
 * answers — the same arrangement Shopping uses for `SellerEnquiryChannel`.
 *
 * The default binding answers null for everything, so consent still records
 * correctly (as "no published document") in a deployment where the pages have
 * not been seeded.
 */
interface PolicyDocuments
{
    /**
     * The live version number of the page with this slug, or null when the
     * page does not exist or has never been published.
     */
    public function currentVersion(string $slug): ?int;

    /**
     * The URL a person should be sent to in order to read it, or null.
     */
    public function url(string $slug): ?string;
}
