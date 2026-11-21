# ATLAS ONE V5 — P036.04 first increment: purchase approval controls

Parent PR #43 creates request drafts. This PR adds `POST /api/purchasing/requests/{id}/submit` and `POST /api/purchasing/requests/{id}/decision`, a tenant-constrained append-only transition log and an optimistic business workflow guarded by database row locks.

| From | Operation | To | Restriction |
|---|---|---|---|
| draft | submit | submitted | original requester, positive estimated budget |
| submitted | approve | approved | different admin from requester, explicit reason |
| submitted | reject | rejected | different admin from requester, explicit reason |

Every transition is atomic with a history event and an audit entry; attempts to repeat the same transition or approve one's own request return **409 / 403** respectively. Only administrators of the active company may use this initial workflow. No money is transferred and **approved is not an accounting commitment or purchase order**.

Remaining scope: P036.02 supplier consultations, P036.03 explicit delegation limits, P036.04 budget reservations and financial commitments, P036.05 generated POs and P036.06 concurrency/fraud verification. No email or external vendor notifications. No production rollout or merge.
