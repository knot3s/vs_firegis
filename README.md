# VAS FireGIS — Emergency Response in Fire (GIS)

A GIS-based fire emergency reporting and command system for the Municipality of Vincenzo A. Sagun, Zamboanga del Sur.

- **Residents** report a fire by pinning it on a map (device GPS is captured automatically).
- **Fire personnel** verify the report (GPS vs. pin distance, 100 m Haversine rule) and drive the response lifecycle.

Pure PHP + HTML/JS. No Node, no npm, no database. Works on any shared hosting (Hostinger, cPanel, XAMPP).

## File structure

```
vs_firegis/
├── api/                    # One small file per API endpoint
│   ├── auth.php            #   POST /api/auth/login
│   ├── barangays.php       #   GET  /api/barangays
│   ├── export.php          #   GET  /api/export/csv  |  /pdf
│   ├── incidents.php       #   GET  /api/incidents  +  PATCH /api/incidents/{id}/status
│   ├── meta.php            #   GET  /api/meta  +  /health
│   ├── reports.php         #   POST /api/reports
│   ├── station.php         #   GET  /api/station
│   └── stream.php          #   GET  /api/stream (live SSE updates)
├── data/
│   └── incidents.json      # Incident records (auto-created; do not commit)
├── lib.php                 # Config + helpers (barangays, haversine, save/load)
├── index.html              # Landing page
├── resident.html           # Resident report form
├── dashboard.html          # Command dashboard (login required)
├── .htaccess               # URL routing (Apache)
└── README.md
```

### Where things live

| What | File |
| --- | --- |
| Barangay list, station coords, login, thresholds | [`lib.php`](lib.php) |
| Submit a fire report | [`api/reports.php`](api/reports.php) |
| List/update incidents | [`api/incidents.php`](api/incidents.php) |
| Login | [`api/auth.php`](api/auth.php) |
| CSV / PDF export | [`api/export.php`](api/export.php) |
| Live updates | [`api/stream.php`](api/stream.php) |

## How to run

### On shared hosting (Hostinger / cPanel) — no commands needed

1. Log in to your hosting panel → **File Manager** → `public_html`.
2. Upload all project files (keep the `api/` and `data/` folders).
3. Right-click the `data/` folder → **Permissions** → `755` (so PHP can write incident records).
4. Open your domain in a browser. Done.

### Locally (XAMPP)

1. Copy the project into `C:\xampp\htdocs\vs_firegis`.
2. Make sure Apache is running in the XAMPP control panel.
3. Visit http://localhost/vs_firegis/

> The PHP built-in server (`php -S`) does **not** read `.htaccess`, so for local testing use XAMPP/Apache.

## Access

| Role | URL | Login |
| --- | --- | --- |
| Resident | `/resident.html` | none |
| Fire Personnel | `/dashboard.html` | `firestaff` / `vas-bfp-2026` |

## How it works

1. A resident opens `/resident.html`, allows GPS, drags the pin to the fire, and submits.
2. The server computes the distance between the **device GPS** and the **pinned location** using the Haversine formula.
   - ≤ 100 m → **Verified Spatial Alert**
   - \> 100 m or no GPS → **Unverified / Spatial Discrepancy**
3. The incident is saved to `data/incidents.json`.
4. Fire personnel log in to `/dashboard.html` and see live incidents on the map + feed.
5. Personnel update the lifecycle: Pending → Dispatched → Under Control → Fire Out (or False Alarm).
6. Data can be exported as CSV or PDF.

## Changing the demo login

Edit these constants at the top of [`lib.php`](lib.php):

```php
const STAFF_USERNAME = 'firestaff';
const STAFF_PASSWORD = 'vas-bfp-2026';
```

## Notes

- Incident data lives in `data/incidents.json`. The folder must be writable by PHP.
- This is a prototype. Barangay boundaries are schematic boxes, not official GIS polygons.
