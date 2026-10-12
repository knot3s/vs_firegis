# EMERGENCY RESPONSE IN FIRE (GIS)

GIS-based emergency response prototype for the **Municipality of Vincenzo A. Sagun, Zamboanga del Sur**.

Two roles:

- **Resident** — mobile-first report form with HTML5 Geolocation + Leaflet pin
- **Fire Personnel** — command dashboard, Haversine verification badges, lifecycle, CSV/PDF export

## Run (Node.js)

```bash
cd C:\xampp\htdocs\vs_firegis
npm install
npm start
```

Open [http://localhost:3000](http://localhost:3000)

| Role | URL | Credentials |
| --- | --- | --- |
| Resident | `/resident.html` | none |
| Fire Personnel | `/dashboard.html` | `firestaff` / `vas-bfp-2026` |

## Spatial verification

Server Haversine (`EARTH_RADIUS_M = 6,371,000`) compares **device GPS** vs **pinned marker**:

- `d ≤ 100 m` → **Verified Spatial Alert** (green)
- `d > 100 m` → **Unverified / Spatial Discrepancy** (yellow)

PostGIS equivalents are documented in `schema.sql` (`ST_Distance` on `geography`).

## Optional PostgreSQL

```bash
psql -d vas_firegis -f schema.sql
set DATABASE_URL=postgres://user:pass@localhost:5432/vas_firegis
npm start
```

Without `DATABASE_URL`, incidents persist in `data/store.json`.

Barangay polygons are **schematic** boxes around Kabatan for the prototype. Replace with PSA/NAMRIA cadastral GeoJSON for operations.

## Lifecycle

`Pending` → `Dispatched` → `Under Control` → `Fire Out` (or `False Alarm`)

## Deploy to the internet

The app is a plain Node.js/Express server — no build step, no native modules.
`npm install` and `npm start` work on any host that runs Node 18+.
The server automatically binds to the host-assigned `PORT` and listens on `0.0.0.0`.

### Recommended hosts (Node.js platform)

Pick any of these — they run `npm install` then `npm start` automatically:

| Host | Type | Notes |
| --- | --- | --- |
| **Render** (Web Service) | free/paid | Set `Start Command` = `npm start`. Add `DATABASE_URL` for persistence. |
| **Railway** | paid | Add a Postgres plugin, then set `DATABASE_URL`. |
| **Fly.io** | paid | `fly launch` — detects Node. |
| **Heroku** | paid | Auto-detects `start` script. Add Heroku Postgres. |
| **VPS / cPanel** | any | Run with PM2: `pm2 start server.js --name vs-firegis`. |

For all hosts: set the environment variable `PORT` is automatic; the app reads it.

### ⚠ Persistence on hosting

Most cloud platforms use an **ephemeral filesystem** — `data/store.json` is wiped on every restart or redeploy. To keep incidents permanently, provision a PostgreSQL database and set:

```
DATABASE_URL=postgres://user:password@host:5432/vas_firegis
```

Then load the schema once:

```bash
psql "$DATABASE_URL" -f schema.sql
```

Without `DATABASE_URL`, the app still runs (JSON mode) but incidents may disappear after a restart. You will see a warning in the logs at startup.

### Vercel / Netlify (serverless) — not recommended

Vercel and Netlify are built for serverless functions, not long-running Express servers. The live alert stream (`/api/stream`, Server-Sent Events) will not work reliably there. Use a Node host from the table above instead.

### Health check

`GET /health` returns `{ ok: true, persistence: "json" | "postgis", uptime }`. Use this as the health-check path on your host.
