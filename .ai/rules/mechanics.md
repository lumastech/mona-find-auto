---
paths:
  - 'app/Modules/Mechanics/**'
---

# Mechanics

## Mechanics: approval gates visibility, endorsements are the shop's own word
A mechanic profile is invisible until staff approve it — not unlisted, absent. Every public read starts from `MechanicProfile::scopePubliclyVisible()` (Approved only), and `MechanicApprovalService` is the only way `status` ever changes. `show` 404s an unapproved profile even for its own author, who reads it on /mechanics/apply instead.

Two independent badges. "MonaFind approved" is `status`; "Endorsed by X" is one `mechanic_endorsements` row per shop. Staff cannot endorse — `EndorsementPolicy` deliberately has no staff override, since one would collapse the second badge into the first. Only the addressed shop may answer or revoke, checked in the policy AND again in `EndorsementService`.

Endorsement rows are never deleted. Decline, revoke and re-ask all move the same row (unique on mechanic+seller), so the badge is a status test and a Seller→Mechanic rating keeps the source that entitled it.

The mechanic role is granted at APPROVAL, not at submission (the opposite of a seller, who needs the portal while under review). It is removed on suspension and restored on reinstatement.

`mechanic_references` and the certificates media collection are staff-only. `MechanicProfileResource` has no branch that can emit them; the admin Show screen is the only surface that loads them.
