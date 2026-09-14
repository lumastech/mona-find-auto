<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One persistent cart per buyer.
     *
     * A row of its own rather than a column on the user, because a cart is
     * the thing lines hang off and because it carries its own timestamps: a
     * cart last touched in March is a different object to talk about than the
     * account that owns it.
     *
     * There is no guest cart. Buyers on low-end Android phones move between
     * the browser and the app, and a cart that lives in a cookie is a cart
     * that vanishes when they do — so the platform asks for a login before
     * the first line rather than losing the cart at checkout.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
