import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import Cart from "@/pages/storefront/Cart.vue";
import type { Cart as CartPayload } from "@/types";

vi.mock("@inertiajs/vue3", () => ({
    Head: { template: "<div />" },
    Link: {
        props: ["href"],
        template: '<a :href="href.url ?? href"><slot /></a>',
    },
    router: { patch: vi.fn(), delete: vi.fn() },
}));

/*
 * The Checkout button is the cart's only way forward. A cart the server has
 * cleared must link to the checkout page; one with a flagged line must not.
 */

const cart = (blocksCheckout: boolean): CartPayload => ({
    groups: [],
    total_ngwee: 45000,
    unit_count: 1,
    line_count: 1,
    seller_count: 1,
    is_empty: false,
    has_changes: false,
    blocks_checkout: blocksCheckout,
    issues: [],
    removed: [],
});

const checkoutControl = (blocksCheckout: boolean) =>
    mount(Cart, {
        props: { cart: cart(blocksCheckout) },
        global: { stubs: { Money: true } },
    })
        .findAll("a, button")
        .find((element) => element.text() === "Checkout");

describe("Cart checkout button", () => {
    it("links to the checkout page when nothing blocks it", () => {
        const control = checkoutControl(false);

        expect(control?.element.tagName).toBe("A");
        expect(control?.attributes("href")).toBe("/checkout");
    });

    it("is a disabled button when a line blocks checkout", () => {
        const control = checkoutControl(true);

        expect(control?.element.tagName).toBe("BUTTON");
        expect(control?.attributes("disabled")).toBeDefined();
    });
});
