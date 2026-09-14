import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MessageBubble from '@/components/messaging/MessageBubble.vue';
import type { ThreadMessage } from '@/types/messaging';

const message = (overrides: Partial<ThreadMessage> = {}): ThreadMessage => ({
    id: 1,
    body: 'Is this still available?',
    is_mine: false,
    is_from_platform: false,
    author: { id: 7, name: 'Mwansa Auto Spares' },
    was_redacted: false,
    redactions: [],
    attachments: [],
    created_at: '2026-09-12T09:30:00+00:00',
    ...overrides,
});

describe('MessageBubble', () => {
    it('puts my own messages on the right and theirs on the left', () => {
        const mine = mount(MessageBubble, {
            props: { message: message({ is_mine: true }) },
        });
        const theirs = mount(MessageBubble, { props: { message: message() } });

        expect(mine.find('li').classes()).toContain('justify-end');
        expect(theirs.find('li').classes()).toContain('justify-start');
    });

    it('names the sender on a message that is not mine', () => {
        const wrapper = mount(MessageBubble, { props: { message: message() } });

        expect(wrapper.text()).toContain('Mwansa Auto Spares');
    });

    /*
     * The reason this component exists. A recipient who sees "[removed]" and
     * is not told why will assume the platform mangled the message — or that
     * the sender is being evasive — and go and ask on WhatsApp, which is the
     * outcome the screen exists to prevent.
     */
    it('says what the screen removed, and to the sender as well', () => {
        const redacted = message({
            body: 'Call me on [removed].',
            was_redacted: true,
            redactions: ['Phone number redacted'],
            is_mine: true,
        });

        const wrapper = mount(MessageBubble, { props: { message: redacted } });

        expect(wrapper.text()).toContain('Phone number redacted');
    });

    it('says nothing about redaction when nothing was removed', () => {
        const wrapper = mount(MessageBubble, { props: { message: message() } });

        expect(wrapper.text()).not.toContain('redacted');
    });

    it('renders a line the platform wrote without a bubble or an author', () => {
        const wrapper = mount(MessageBubble, {
            props: {
                message: message({
                    is_from_platform: true,
                    author: null,
                    body: 'This order was cancelled.',
                }),
            },
        });

        expect(wrapper.text()).toContain('This order was cancelled.');
        expect(wrapper.find('.bg-primary').exists()).toBe(false);
    });

    it('shows images as thumbnails and other files as links', () => {
        const wrapper = mount(MessageBubble, {
            props: {
                message: message({
                    attachments: [
                        {
                            id: 1,
                            name: 'part.jpg',
                            mime_type: 'image/jpeg',
                            size: 1024,
                            url: '/media/1/part.jpg',
                            thumb_url: '/media/1/thumb.webp',
                        },
                        {
                            id: 2,
                            name: 'receipt.pdf',
                            mime_type: 'application/pdf',
                            size: 2048,
                            url: '/media/2/receipt.pdf',
                            thumb_url: null,
                        },
                    ],
                }),
            },
        });

        expect(wrapper.find('img').attributes('src')).toBe(
            '/media/1/thumb.webp',
        );
        expect(wrapper.text()).toContain('receipt.pdf');
    });
});
