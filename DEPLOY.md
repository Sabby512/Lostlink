# Deploy LostLink to Vercel + Supabase

## 1. Supabase
1. SQL Editor -> paste `sql/schema.sql` -> Run (creates all tables, security rules and the 4 storage buckets).
2. SQL Editor -> paste `sql/create-admin.sql` -> Run. Log in as admin@gmail.com / Admin@12345 and change the password.
3. Project Settings -> Database -> Connection string -> **Transaction pooler** (port 6543). Copy it into `DATABASE_URL`.
4. Project Settings -> API -> copy the Project URL (`SUPABASE_URL`) and the `service_role` key (`SUPABASE_SERVICE_KEY`).

## 2. Vercel
1. Put `logo.png` (and any other images) in `public/`.
2. Push this folder to GitHub, import it in Vercel. Framework Preset: **Other**. Leave build/output settings empty (vercel.json sets them).
3. Add every variable from `.env.example` under Settings -> Environment Variables, then redeploy.
4. Test: open `https://YOUR-APP.vercel.app/api/auth.php?action=me`. You should see `{"error":"Please log in."}`.
   If you see a "Server error", add `DEBUG_ERRORS=1`, redeploy and read the message.

## Known limits on Vercel
* Request bodies are limited to 4.5 MB (a PDF proof over about 3 MB will be refused). Responses too.
* No server-side image conversion (no GD). Black & white copies are made by the browser, as db.js already does.
* bw_all.php and mail-test.php were not ported (GD tool / temporary test page).
