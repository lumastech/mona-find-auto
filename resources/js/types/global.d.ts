import type { Auth } from '@/types/auth';
import type { MessagingCounts } from '@/types/messaging';
import type { StorefrontNav } from '@/types/navigation';
import type { Captcha, Platform } from '@/types/platform';
import type { ShoppingCounts } from '@/types/shopping';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            platform: Platform;
            captcha: Captcha;
            settings: Record<string, unknown>;
            /**
             * The header's cart and wishlist badges. Null outside the
             * storefront — the seller portal and staff console never resolve
             * the closure that builds it.
             */
            shopping: ShoppingCounts | null;
            /**
             * The header's category menu and vehicle picker. Null outside the
             * storefront, for the same reason as `shopping` above.
             */
            nav: StorefrontNav | null;
            /**
             * The bell's two numbers. Null for a guest — there is no bell to
             * draw, and a zero would make one appear.
             */
            messaging: MessagingCounts | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
