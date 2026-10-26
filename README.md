# Project Subaybay Node.js API

This directory contains the Express API used by the React/Vite frontend. It connects to the existing MySQL-compatible schema (MariaDB or TiDB Cloud) and does not run Laravel or modify the schema automatically.

## Local development

Requirements: Node.js 20+ and npm. Copy `.env.example` to `.env` and set the database connection. From this directory run:

```sh
npm install
npm run dev
```

The API listens on `http://127.0.0.1:8000`; its health routes are `/up` and `/api/health`. Set the frontend's `VITE_API_BASE_URL` to `http://127.0.0.1:8000/api`.

For TiDB Cloud, use its **Public** connection host and port, allowlist Render's outbound IP ranges, and enable TLS with `DB_SSL=true`. Do not put database credentials in frontend environment variables.

## Render

The root `render.yaml` configures a Node web service with `npm ci` and `npm start`. Configure `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and `FRONTEND_ORIGINS` in Render. Render Free has an ephemeral filesystem, so uploaded documents and materials can be lost after a restart. Account profile photos are stored in TiDB after applying the migration below.

The API reuses the existing `personal_access_tokens` table format so the frontend's Bearer token flow remains compatible. Existing valid tokens in that table can continue to work; new logins issue tokens in the same format.

### Persistent profile photos

Before deploying profile photo uploads, run [`database/profile_images_tidb.sql`](database/profile_images_tidb.sql) once against the production TiDB database. The API stores account profile photo bytes in this table so they survive Render restarts. The database account used by the API only needs normal `SELECT` and `INSERT`/`UPDATE` access to the table after it has been created.
