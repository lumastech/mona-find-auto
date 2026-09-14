<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sellers\Enums\DocumentType;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerDocumentService;
use App\Support\Roles\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A fake upload the mime sniffer actually reads as a PDF.
 *
 * UploadedFile::fake()->create() produces an empty file, which sniffs as
 * application/x-empty and is refused by the collection — correctly, since a
 * zero-byte certificate is no use to a reviewer either.
 */
function fakePdf(string $name = 'pacra.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF",
    );
}

beforeEach(function () {
    Storage::fake('local');
    $this->documents = app(SellerDocumentService::class);
    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
});

it('stores an uploaded document on the private disk', function () {
    $media = $this->documents->attach(
        $this->seller,
        DocumentType::CertificateOfIncorporation,
        fakePdf('pacra.pdf'),
    );

    expect($media->disk)->toBe('local')
        ->and($media->collection_name)->toBe('documents')
        ->and($media->getCustomProperty('document_type'))->toBe('certificate_of_incorporation')
        ->and($media->file_name)->toBe($this->seller->slug.'-certificate-of-incorporation.pdf');
});

it('replaces a document rather than accumulating copies of it', function () {
    foreach (['first.pdf', 'second.pdf'] as $name) {
        $this->documents->attach(
            $this->seller,
            DocumentType::OwnerIdentification,
            fakePdf($name),
        );
    }

    expect($this->seller->refresh()->getMedia('documents'))->toHaveCount(1);
});

it('asks a garage for a trade licence and a parts shop not', function () {
    $garage = Seller::factory()->ofType(SellerType::Garage)->create();

    expect($garage->type->requiredDocuments())->toContain(DocumentType::TradeLicence)
        ->and($this->seller->type->requiredDocuments())->not->toContain(DocumentType::TradeLicence);
});

it('reports which documents are still missing', function () {
    expect($this->seller->missingDocuments())->toHaveCount(2);

    $this->documents->attach(
        $this->seller,
        DocumentType::CertificateOfIncorporation,
        fakePdf('pacra.pdf'),
    );

    expect($this->seller->refresh()->missingDocuments())->toBe([DocumentType::OwnerIdentification]);
});

it('lets a reviewer open a document and records that they did', function () {
    $media = $this->documents->attach(
        $this->seller,
        DocumentType::CertificateOfIncorporation,
        fakePdf('pacra.pdf'),
    );

    $moderator = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();

    $this->actingAs($moderator)
        ->get(route('admin.sellers.documents.show', ['seller' => $this->seller, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="'.$media->file_name.'"')
        /* Somebody's ID document must not sit in a proxy cache. */
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'seller.document.viewed',
        'subject_id' => $this->seller->id,
    ]);
});

it('will not serve a document through another seller\'s url', function () {
    $media = $this->documents->attach(
        $this->seller,
        DocumentType::CertificateOfIncorporation,
        fakePdf('pacra.pdf'),
    );

    $other = Seller::factory()->create();
    $moderator = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();

    $this->actingAs($moderator)
        ->get(route('admin.sellers.documents.show', ['seller' => $other, 'media' => $media]))
        ->assertNotFound();
});

it('keeps documents away from buyers and other sellers', function () {
    $media = $this->documents->attach(
        $this->seller,
        DocumentType::CertificateOfIncorporation,
        fakePdf('pacra.pdf'),
    );

    $intruder = Seller::factory()->create();

    $this->actingAs($intruder->user)
        ->get(route('admin.sellers.documents.show', ['seller' => $this->seller, 'media' => $media]))
        ->assertForbidden();
});

it('refuses a file that is not a document', function () {
    $this->actingAs($this->seller->user)
        ->post(route('seller.documents.store'), [
            'document_type' => DocumentType::OwnerIdentification->value,
            'file' => UploadedFile::fake()->create('malware.exe', 10),
        ])
        ->assertSessionHasErrors('file');
});
