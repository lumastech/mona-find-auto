import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";
import Pay from "@/pages/storefront/payments/Pay.vue";

vi.mock("@inertiajs/vue3", () => ({
    Head: { template: "<div />" },
    router: { visit: vi.fn() },
    useHttp: () => ({ submit: vi.fn() }),
}));

/*
 * Lenco's inline widget documents `amount` as a number in kwacha and `email`
 * as a top-level field. The server sends the amount as a decimal string so it
 * never passes through a PHP float; the page must convert it at the hand-off.
 *
 * The widget forwards the config to its iframe with postMessage, so it must
 * be structured-cloneable. Inertia page props are reactive proxies, which are
 * not — the widget then hangs on its spinner without reporting anything.
 */

const lenco = {
    publicKey: "pub-test",
    widgetUrl: "https://pay.sandbox.lenco.co/js/v1/inline.js",
    environment: "sandbox",
    reference: "MFA-01ABC-1",
    attempt: 1,
    amount: "1234.50",
    amountNgwee: 123450,
    currency: "ZMW",
    channels: ["card"],
    bearer: "merchant",
    label: "MonaFind order 01ABC",
    customer: { email: "buyer@example.com", firstName: "Chanda" },
    billing: { country: "ZM" },
    verifyUrl: "/pay/01ABC/verify",
    statusUrl: "/pay/01ABC/status",
};

afterEach(() => {
    delete window.LencoPay;
    document.head
        .querySelectorAll(`script[src="${lenco.widgetUrl}"]`)
        .forEach((script) => script.remove());
});

const mountPay = () =>
    mount(Pay, {
        props: {
            group: {
                publicId: "01ABC",
                totalNgwee: 123450,
                itemsTotalNgwee: 123450,
                deliveryTotalNgwee: 0,
                sellers: [],
            },
            lenco: reactive(structuredClone(lenco)),
        },
        global: { stubs: { Money: true } },
    });

const widgetScript = () =>
    document.head.querySelector<HTMLScriptElement>(
        `script[src="${lenco.widgetUrl}"]`,
    );

describe("Lenco pay widget hand-off", () => {
    it("passes the amount as a number and email at the top level", async () => {
        const getPaid = vi.fn();
        window.LencoPay = { getPaid };

        const wrapper = mountPay();

        await wrapper.find("button").trigger("click");
        await flushPromises();

        const config = getPaid.mock.calls[0][0];

        expect(config.amount).toBe(1234.5);
        expect(config.email).toBe("buyer@example.com");
        expect(config.customer).toEqual({ firstName: "Chanda" });

        const { onSuccess, onClose, onConfirmationPending, ...data } = config;
        expect(() => structuredClone(data)).not.toThrow();
    });

    it("retries the script after a failed load instead of hanging", async () => {
        /* jsdom refuses to fetch scripts, so every load attempt errors. */
        const append = vi.spyOn(document.head, "appendChild");
        const wrapper = mountPay();
        await flushPromises();

        await wrapper.find("button").trigger("click");
        await flushPromises();

        const attempts = append.mock.calls.filter(
            ([node]) => (node as HTMLScriptElement).src === lenco.widgetUrl,
        );

        expect(attempts).toHaveLength(2);
        expect(widgetScript()).toBeNull();
        expect(wrapper.find("button").attributes("disabled")).toBeUndefined();
        expect(wrapper.text()).toContain("could not load");

        append.mockRestore();
    });
});
