/**
 * EMERGENCY RESPONSE IN FIRE (GIS)
 * Municipality of Vincenzo A. Sagun, Zamboanga del Sur
 *
 * Express REST API:
 *   POST /api/reports              resident incident submission
 *   GET  /api/incidents            command feed (newest first)
 *   PATCH /api/incidents/:id/status  lifecycle update
 *   GET  /api/barangays            GeoJSON FeatureCollection
 *   GET  /api/export/csv | /pdf    incident history download
 *   GET  /api/stream               Server-Sent Events for live alerts
 *
 * Persistence: PostgreSQL + PostGIS when DATABASE_URL is set;
 *              otherwise a local JSON store so the prototype runs on XAMPP/Node
 *              without a spatial database install.
 */

const fs = require("fs");
const path = require("path");
const express = require("express");
const cors = require("cors");

const PORT = Number(process.env.PORT) || 3000;
const DATA_DIR = path.join(__dirname, "data");
const STORE_PATH = path.join(DATA_DIR, "store.json");
const PUBLIC_DIR = path.join(__dirname, "public");

/** Municipal map origin (user spec ~7.5333, 123.1667; PhilAtlas poblacion overlay). */
const MAP_CENTER = { lat: 7.5333, lng: 123.1667 };
/** VAS Fire Station / municipal hall — Barangay Kabatan (Poblacion). */
const STATION = {
  name: "VAS Fire Station (Kabatan)",
  lat: 7.5164,
  lng: 123.1697,
  address: "Poblacion, Kabatan, Vincenzo A. Sagun, Zamboanga del Sur",
};

/** Mean Earth radius used by the Haversine engine (IUGG conventional value). */
const EARTH_RADIUS_M = 6371000;
/** Operational spatial-verification threshold. */
const VERIFY_THRESHOLD_M = 100;

const LIFECYCLE = ["Pending", "Dispatched", "Under Control", "Fire Out", "False Alarm"];

/**
 * Schematic 14-barangay jurisdictions for prototype mapping.
 * Centroids are offset around Kabatan; polygons are ~1.4 km boxes.
 * Replace with official PSA/NAMRIA cadastral GeoJSON in production.
 */
const BARANGAYS = [
  { name: "Kabatan", isPoblacion: true, population: 5059, lat: 7.5164, lng: 123.1697 },
  { name: "Danan", isPoblacion: false, population: 2609, lat: 7.5220, lng: 123.1820 },
  { name: "Ambulon", isPoblacion: false, population: 1302, lat: 7.5280, lng: 123.1750 },
  { name: "Kapatagan", isPoblacion: false, population: 1716, lat: 7.5100, lng: 123.1850 },
  { name: "Limason", isPoblacion: false, population: 1683, lat: 7.5200, lng: 123.1550 },
  { name: "Lunib", isPoblacion: false, population: 1714, lat: 7.5080, lng: 123.1520 },
  { name: "Cogon", isPoblacion: false, population: 1574, lat: 7.5000, lng: 123.1600 },
  { name: "Bui-os", isPoblacion: false, population: 1479, lat: 7.4980, lng: 123.1720 },
  { name: "Maraya", isPoblacion: false, population: 1430, lat: 7.4920, lng: 123.1800 },
  { name: "Sagucan", isPoblacion: false, population: 1272, lat: 7.5050, lng: 123.1900 },
  { name: "Waling-waling", isPoblacion: false, population: 1818, lat: 7.5300, lng: 123.1600 },
  { name: "Lumbal", isPoblacion: false, population: 1282, lat: 7.5350, lng: 123.1680 },
  { name: "Linoguayan", isPoblacion: false, population: 930, lat: 7.5250, lng: 123.1480 },
  { name: "Maculay", isPoblacion: false, population: 984, lat: 7.5150, lng: 123.1400 },
];

function squarePolygon(lat, lng, halfKm = 0.7) {
  const dLat = halfKm / 111.32;
  const dLng = halfKm / (111.32 * Math.cos((lat * Math.PI) / 180));
  // GeoJSON rings are [lng, lat]
  const ring = [
    [lng - dLng, lat - dLat],
    [lng + dLng, lat - dLat],
    [lng + dLng, lat + dLat],
    [lng - dLng, lat + dLat],
    [lng - dLng, lat - dLat],
  ];
  return [ring];
}

function barangaysAsGeoJSON() {
  return {
    type: "FeatureCollection",
    name: "VAS_Barangay_Zones_Schematic",
    crs: { type: "name", properties: { name: "EPSG:4326" } },
    features: BARANGAYS.map((b, i) => ({
      type: "Feature",
      id: i + 1,
      properties: {
        id: i + 1,
        name: b.name,
        is_poblacion: b.isPoblacion,
        population_2020: b.population,
      },
      geometry: {
        type: "Polygon",
        coordinates: squarePolygon(b.lat, b.lng),
      },
    })),
  };
}

// =============================================================================
// Haversine verification engine
// =============================================================================

function toRad(deg) {
  return (deg * Math.PI) / 180;
}

/**
 * Great-circle distance in meters between two WGS84 points.
 *
 * Formula (spherical law of haversines):
 *   Δφ = φ2 − φ1          (latitude delta, radians)
 *   Δλ = λ2 − λ1          (longitude delta, radians)
 *   a  = sin²(Δφ/2) + cos(φ1) · cos(φ2) · sin²(Δλ/2)
 *   c  = 2 · atan2(√a, √(1−a))     // central angle
 *   d  = R · c                     // R = 6,371,000 m
 *
 * atan2 is numerically stable when points are nearly coincident (small a),
 * which matters for the 100 m classification threshold.
 *
 * PostGIS equivalent on geography columns:
 *   ST_Distance(device_geom, pinned_geom)  -- meters on WGS84 spheroid
 */
function haversineMeters(lat1, lng1, lat2, lng2) {
  if (![lat1, lng1, lat2, lng2].every((n) => Number.isFinite(Number(n)))) {
    return null;
  }
  const φ1 = toRad(Number(lat1));
  const φ2 = toRad(Number(lat2));
  const Δφ = toRad(Number(lat2) - Number(lat1));
  const Δλ = toRad(Number(lng2) - Number(lng1));

  const sinΔφ = Math.sin(Δφ / 2);
  const sinΔλ = Math.sin(Δλ / 2);
  const a = sinΔφ * sinΔφ + Math.cos(φ1) * Math.cos(φ2) * sinΔλ * sinΔλ;
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return EARTH_RADIUS_M * c;
}

function classifySpatial(deviceLat, deviceLng, pinnedLat, pinnedLng) {
  const d = haversineMeters(deviceLat, deviceLng, pinnedLat, pinnedLng);
  if (d === null) {
    return {
      discrepancy_meters: null,
      spatial_status: "Unverified / Spatial Discrepancy",
      reason: "Device GPS unavailable — cannot corroborate pinned location.",
    };
  }
  const verified = d <= VERIFY_THRESHOLD_M;
  return {
    discrepancy_meters: Math.round(d * 10) / 10,
    spatial_status: verified ? "Verified Spatial Alert" : "Unverified / Spatial Discrepancy",
    reason: verified
      ? `Pinned marker is ${Math.round(d)} m from device GPS (<= ${VERIFY_THRESHOLD_M} m).`
      : `Pinned marker is ${Math.round(d)} m from device GPS (> ${VERIFY_THRESHOLD_M} m).`,
  };
}

// =============================================================================
// Persistence
// =============================================================================

const sseClients = new Set();

function defaultStore() {
  return {
    users: [
      {
        id: "usr-command-001",
        full_name: "VAS Fire Station Command",
        phone: "0917-000-0000",
        username: "firestaff",
        password: "vas-bfp-2026",
        role: "fire_personnel",
      },
    ],
    incidents: [],
  };
}

function readStore() {
  try {
    return JSON.parse(fs.readFileSync(STORE_PATH, "utf8"));
  } catch {
    return defaultStore();
  }
}

function writeStore(store) {
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(STORE_PATH, JSON.stringify(store, null, 2), "utf8");
  } catch (err) {
    // On read-only / ephemeral filesystems writes may fail; log and continue
    // so the API stays up. Use PostgreSQL (DATABASE_URL) for durable storage.
    console.error("[store] write failed (ephemeral filesystem?):", err.message);
  }
}

function broadcast(event, payload) {
  const frame = `event: ${event}\ndata: ${JSON.stringify(payload)}\n\n`;
  for (const res of sseClients) {
    res.write(frame);
  }
}

let pgPool = null;
async function tryPostgres() {
  if (!process.env.DATABASE_URL) return;
  try {
    const { Pool } = require("pg");
    pgPool = new Pool({ connectionString: process.env.DATABASE_URL });
    await pgPool.query("SELECT 1");
    console.log("[db] PostgreSQL connected — PostGIS path enabled");
  } catch (err) {
    pgPool = null;
    console.warn("[db] PostgreSQL unavailable, using JSON store:", err.message);
  }
}

async function insertIncidentPg(row) {
  const sql = `
    INSERT INTO incidents (
      reporter_name, reporter_phone, barangay_name, notes,
      device_lat, device_lng, pinned_lat, pinned_lng,
      device_geom, pinned_geom, discrepancy_meters, spatial_status, lifecycle_status
    ) VALUES (
      $1,$2,$3,$4,$5,$6,$7,$8,
      CASE WHEN $5 IS NULL THEN NULL
           ELSE ST_SetSRID(ST_MakePoint($6,$5),4326)::geography END,
      ST_SetSRID(ST_MakePoint($8,$7),4326)::geography,
      $9,$10,'Pending'
    )
    RETURNING id::text, created_at
  `;
  const r = await pgPool.query(sql, [
    row.reporter_name,
    row.reporter_phone,
    row.barangay_name,
    row.notes,
    row.device_lat,
    row.device_lng,
    row.pinned_lat,
    row.pinned_lng,
    row.discrepancy_meters,
    row.spatial_status,
  ]);
  return r.rows[0];
}

// =============================================================================
// App
// =============================================================================

const app = express();
app.use(cors());
app.use(express.json({ limit: "1mb" }));
app.use(express.static(PUBLIC_DIR));

app.get("/api/meta", (_req, res) => {
  res.json({
    title: "EMERGENCY RESPONSE IN FIRE (GIS)",
    municipality: "Vincenzo A. Sagun, Zamboanga del Sur",
    mapCenter: MAP_CENTER,
    station: STATION,
    verifyThresholdMeters: VERIFY_THRESHOLD_M,
    earthRadiusMeters: EARTH_RADIUS_M,
    lifecycle: LIFECYCLE,
    persistence: pgPool ? "postgis" : "json",
  });
});

app.get("/api/barangays", (_req, res) => {
  res.json(barangaysAsGeoJSON());
});

app.get("/api/station", (_req, res) => {
  res.json(STATION);
});

app.post("/api/auth/login", (req, res) => {
  const { username, password, role } = req.body || {};
  if (role === "resident") {
    return res.json({
      ok: true,
      role: "resident",
      token: "resident-public",
      name: "Public Reporter",
    });
  }
  const store = readStore();
  const user = store.users.find(
    (u) => u.username === username && u.password === password && u.role === "fire_personnel"
  );
  if (!user) {
    return res.status(401).json({ ok: false, error: "Invalid fire personnel credentials." });
  }
  res.json({
    ok: true,
    role: "fire_personnel",
    token: "personnel-" + user.id,
    name: user.full_name,
    username: user.username,
  });
});

app.post("/api/reports", async (req, res) => {
  const b = req.body || {};
  const reporter_name = String(b.reporter_name || "").trim();
  const reporter_phone = String(b.reporter_phone || "").trim();
  const barangay_name = String(b.barangay_name || "").trim();
  const notes = String(b.notes || "").trim();
  const pinned_lat = Number(b.pinned_lat);
  const pinned_lng = Number(b.pinned_lng);
  const device_lat = b.device_lat === null || b.device_lat === "" ? null : Number(b.device_lat);
  const device_lng = b.device_lng === null || b.device_lng === "" ? null : Number(b.device_lng);

  if (!reporter_name || !reporter_phone || !barangay_name) {
    return res.status(400).json({ error: "Name, phone, and barangay are required." });
  }
  if (!Number.isFinite(pinned_lat) || !Number.isFinite(pinned_lng)) {
    return res.status(400).json({ error: "Pinned coordinates are required." });
  }
  if (!BARANGAYS.some((x) => x.name === barangay_name)) {
    return res.status(400).json({ error: "Unknown barangay." });
  }

  const spatial = classifySpatial(device_lat, device_lng, pinned_lat, pinned_lng);
  const incident = {
    id: "INC-" + Date.now().toString(36).toUpperCase() + "-" + Math.floor(Math.random() * 900 + 100),
    reporter_name,
    reporter_phone,
    barangay_name,
    notes,
    device_lat: Number.isFinite(device_lat) ? device_lat : null,
    device_lng: Number.isFinite(device_lng) ? device_lng : null,
    pinned_lat,
    pinned_lng,
    discrepancy_meters: spatial.discrepancy_meters,
    spatial_status: spatial.spatial_status,
    spatial_reason: spatial.reason,
    lifecycle_status: "Pending",
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    updated_by: null,
  };

  if (pgPool) {
    try {
      const row = await insertIncidentPg(incident);
      incident.id = row.id;
      incident.created_at = row.created_at;
    } catch (err) {
      console.error("[pg insert]", err.message);
    }
  }

  const store = readStore();
  store.incidents.push(incident);
  writeStore(store);
  broadcast("incident", incident);
  res.status(201).json({ ok: true, incident });
});

app.get("/api/incidents", (req, res) => {
  const store = readStore();
  const status = req.query.lifecycle;
  let rows = [...store.incidents].sort(
    (a, b) => new Date(b.created_at) - new Date(a.created_at)
  );
  if (status) rows = rows.filter((i) => i.lifecycle_status === status);
  res.json({ count: rows.length, incidents: rows });
});

app.patch("/api/incidents/:id/status", (req, res) => {
  const next = String(req.body?.lifecycle_status || "");
  if (!LIFECYCLE.includes(next)) {
    return res.status(400).json({ error: "Invalid lifecycle status.", allowed: LIFECYCLE });
  }
  const store = readStore();
  const incident = store.incidents.find((i) => i.id === req.params.id);
  if (!incident) return res.status(404).json({ error: "Incident not found." });
  incident.lifecycle_status = next;
  incident.updated_at = new Date().toISOString();
  incident.updated_by = req.body?.updated_by || "fire_personnel";
  writeStore(store);
  broadcast("status", incident);
  res.json({ ok: true, incident });
});

app.get("/api/stream", (req, res) => {
  res.setHeader("Content-Type", "text/event-stream; charset=utf-8");
  res.setHeader("Cache-Control", "no-cache, no-transform");
  res.setHeader("Connection", "keep-alive");
  // Disable proxy buffering so SSE works behind Nginx / Render / Cloudflare.
  res.setHeader("X-Accel-Buffering", "no");
  res.flushHeaders?.();
  res.write(`event: hello\ndata: ${JSON.stringify({ ok: true, t: Date.now() })}\n\n`);
  sseClients.add(res);
  req.on("close", () => sseClients.delete(res));
});

function csvEscape(v) {
  const s = v == null ? "" : String(v);
  if (/[",\n]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

app.get("/api/export/csv", (req, res) => {
  const rows = [...readStore().incidents].sort(
    (a, b) => new Date(b.created_at) - new Date(a.created_at)
  );
  const headers = [
    "id",
    "created_at",
    "barangay_name",
    "reporter_name",
    "reporter_phone",
    "notes",
    "device_lat",
    "device_lng",
    "pinned_lat",
    "pinned_lng",
    "discrepancy_meters",
    "spatial_status",
    "lifecycle_status",
  ];
  const lines = [headers.join(",")].concat(
    rows.map((r) => headers.map((h) => csvEscape(r[h])).join(","))
  );
  const stamp = new Date().toISOString().slice(0, 10);
  res.setHeader("Content-Type", "text/csv; charset=utf-8");
  res.setHeader("Content-Disposition", `attachment; filename="vas-firegis-incidents-${stamp}.csv"`);
  res.send("\uFEFF" + lines.join("\n"));
});

/**
 * Minimal PDF stub (no extra library). Generates a one-page text log
 * that spreadsheet/PDF viewers can open. Production should swap in
 * a proper report engine (e.g. PDFKit / wkhtmltopdf).
 */
app.get("/api/export/pdf", (req, res) => {
  const rows = [...readStore().incidents].sort(
    (a, b) => new Date(b.created_at) - new Date(a.created_at)
  );
  const title = "VAS FireGIS Incident Log";
  const lines = [
    title,
    "Municipality of Vincenzo A. Sagun, Zamboanga del Sur",
    `Exported: ${new Date().toISOString()}`,
    `Records: ${rows.length}`,
    "",
    ...rows.map((r, i) => {
      return [
        `${i + 1}. ${r.id}  [${r.lifecycle_status}]  ${r.spatial_status}`,
        `   ${r.created_at}  Brgy. ${r.barangay_name}`,
        `   Reporter: ${r.reporter_name}  ${r.reporter_phone}`,
        `   Device: ${r.device_lat ?? "n/a"}, ${r.device_lng ?? "n/a"}`,
        `   Pinned: ${r.pinned_lat}, ${r.pinned_lng}  d=${r.discrepancy_meters ?? "n/a"} m`,
        `   Notes: ${r.notes || "-"}`,
        "",
      ].join("\n");
    }),
  ];
  const body = lines.join("\n").replace(/[()]/g, " ");
  // Split into PDF show-text lines (72-point page, Helvetica 9).
  const wrapped = body.split("\n").slice(0, 60);
  let y = 800;
  const cmds = wrapped
    .map((line) => {
      const safe = line.replace(/\\/g, "\\\\").slice(0, 110);
      const op = `BT /F1 9 Tf 40 ${y} Td (${safe}) Tj ET`;
      y -= 12;
      return op;
    })
    .join("\n");
  const stream = `${cmds}\n`;
  const objects = [
    "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj",
    "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj",
    "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj",
    `4 0 obj << /Length ${Buffer.byteLength(stream)} >> stream\n${stream}endstream endobj`,
    "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj",
  ];
  let pdf = "%PDF-1.4\n";
  const offsets = [0];
  objects.forEach((obj) => {
    offsets.push(Buffer.byteLength(pdf));
    pdf += obj + "\n";
  });
  const xrefPos = Buffer.byteLength(pdf);
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  for (let i = 1; i < offsets.length; i++) {
    pdf += String(offsets[i]).padStart(10, "0") + " 00000 n \n";
  }
  pdf += `trailer << /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefPos}\n%%EOF`;
  const stamp = new Date().toISOString().slice(0, 10);
  res.setHeader("Content-Type", "application/pdf");
  res.setHeader("Content-Disposition", `attachment; filename="vas-firegis-incidents-${stamp}.pdf"`);
  res.send(Buffer.from(pdf, "utf8"));
});

app.get("/", (_req, res) => {
  res.sendFile(path.join(PUBLIC_DIR, "index.html"));
});

// Health check — used by Render / Railway / Heroku load balancers.
app.get("/health", (_req, res) => {
  res.json({ ok: true, persistence: pgPool ? "postgis" : "json", uptime: process.uptime() });
});

async function main() {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  if (!fs.existsSync(STORE_PATH)) writeStore(defaultStore());
  await tryPostgres();
  app.listen(PORT, "0.0.0.0", () => {
    console.log(`VAS FireGIS listening on http://localhost:${PORT}`);
    console.log(`  Resident  → http://localhost:${PORT}/resident.html`);
    console.log(`  Dashboard → http://localhost:${PORT}/dashboard.html`);
    console.log(`  Personnel login: firestaff / vas-bfp-2026`);
    if (!pgPool) {
      console.warn("");
      console.warn("  ⚠  JSON store in-memory/ephemeral mode");
      console.warn("     Incidents are saved to data/store.json.");
      console.warn("     On most hosting platforms the filesystem is ephemeral —");
      console.warn("     data is wiped on every restart/redeploy.");
      console.warn("     Set DATABASE_URL to a PostgreSQL instance for durable storage.");
    }
  });
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
