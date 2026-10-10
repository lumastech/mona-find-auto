---
paths:
  - 'resources/js/**'
---

# Js

## Three Inertia areas, lowercase page directories, nullable auth user
Page directories are lowercase (`pages/storefront`, `pages/seller`, `pages/admin`, `pages/auth`, `pages/settings`) — `resources/js/app.ts` picks the layout from that prefix, so a page's directory decides its shell.

- `auth.user` is `User | null` because guests browse the whole storefront. On authenticated-only surfaces use `useAuthenticatedUser()` rather than asserting non-null in a template.
- Shared props also carry `platform.currency`, `platform.timezone`, `auth.roles/isSeller/isStaff` and public `settings`.
- Route helpers come from Wayfinder: `import seller from '@/routes/seller'`. Regenerate with `php artisan wayfinder:generate --with-form` after adding routes (the `--with-form` flag matters; without it `.form()` disappears and vue-tsc fails).

## Pass plain copies of props to third-party widgets (postMessage)
Inertia page props are Vue reactive Proxies. Anything handed to a third-party script that forwards it via postMessage (e.g. Lenco's LencoPay.getPaid) must be a plain clone (JSON round-trip or toRaw+copy), or structured clone throws DataCloneError inside the vendor script and the widget silently hangs on its spinner. See storefront/payments/Pay.vue.
Also: the sandbox widget host pay.sandbox.lenco.co was refusing TLS as of 2026-10-10.
