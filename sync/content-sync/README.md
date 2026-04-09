Content Sync Snapshot

This folder is used by the admin-only `Content Sync` page.

Typical team workflow:

1. Make homepage or shop changes from the admin dashboard.
2. Open `Admin Dashboard -> Content Sync`.
3. Click `Update Repo Snapshot`.
4. Commit and push `sync/content-sync/latest-content-sync.json`.
   If the snapshot is large, commit the generated `latest-content-sync.part*.chunk` files too.
5. Teammates pull the repo and import that snapshot from the same admin page.

The generated snapshot is not created until an admin exports it from the dashboard.
