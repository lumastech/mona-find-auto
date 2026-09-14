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
