import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';
import type { User } from '@/types';

/**
 * The signed-in account, for surfaces that sit behind auth middleware.
 *
 * The shared `auth.user` prop is nullable because guests browse the whole
 * storefront. Inside the seller portal, staff console, settings and other
 * authenticated shells a user is always present, and this composable narrows
 * the type rather than scattering assertions through templates.
 */
export function useAuthenticatedUser(): ComputedRef<User> {
    const page = usePage();

    return computed(() => {
        const user = page.props.auth.user;

        if (!user) {
            throw new Error(
                'useAuthenticatedUser() was called on a page reachable by guests. Read page.props.auth.user directly and handle null there.',
            );
        }

        return user;
    });
}
