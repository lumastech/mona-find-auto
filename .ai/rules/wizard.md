---
paths:
  - 'resources/js/components/seller/wizard/**'
---

# Wizard

## Sign-up wizard must never post to /seller routes
Applicants don't hold Role::Seller until they submit, so any `seller.*` (portal) route 403s from EnsureSellerAccess. Wizard steps use `sellers.register.*` routes (app/Modules/Sellers/routes/web.php), which mount the same portal controllers (PayoutAccountController, DocumentController) behind auth+verified only; those controllers return `back()` so both areas land on their own page.
