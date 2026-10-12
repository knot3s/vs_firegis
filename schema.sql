-- =============================================================================
-- EMERGENCY RESPONSE IN FIRE (GIS)
-- Municipality of Vincenzo A. Sagun, Zamboanga del Sur
-- PostgreSQL + PostGIS schema
--
-- Spatial reference: EPSG:4326 (WGS 84 lon/lat)
-- Distance checks in the live prototype are computed with Haversine in Node.js.
-- Equivalent PostGIS meter distances use geography + ST_Distance (spheroid).
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---------------------------------------------------------------------------
-- USERS  (exactly two operational roles)
-- role: 'resident' | 'fire_personnel'
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    full_name       TEXT NOT NULL,
    phone           TEXT,
    username        TEXT UNIQUE,
    password_hash   TEXT,
    role            TEXT NOT NULL CHECK (role IN ('resident', 'fire_personnel')),
    station_code    TEXT DEFAULT 'VAS-BFP',
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------------
-- BARANGAY ZONES
-- geom: polygonal jurisdiction used for map overlay and point-in-polygon.
-- centroid: POINT used as a fallback pin / label location.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS barangay_zones (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL UNIQUE,
    psgc_code       TEXT,
    is_poblacion    BOOLEAN NOT NULL DEFAULT FALSE,
    population_2020 INTEGER,
    -- Multi-ring polygon in WGS84. Stored as geography so ST_Distance /
    -- ST_DWithin return meters on the spheroid instead of degrees.
    geom            GEOGRAPHY(POLYGON, 4326) NOT NULL,
    centroid        GEOGRAPHY(POINT, 4326) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_barangay_geom
    ON barangay_zones USING GIST (geom);

-- ---------------------------------------------------------------------------
-- INCIDENTS
-- device_*  = HTML5 Geolocation lock from the reporter's device
-- pinned_*  = Leaflet marker the resident tapped/dragged
-- spatial_status is assigned by the Haversine (or ST_Distance) engine:
--   'Verified Spatial Alert'            when d <= 100 m
--   'Unverified / Spatial Discrepancy'  when d >  100 m
-- lifecycle_status is the command-staff workflow.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS incidents (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    reporter_name       TEXT NOT NULL,
    reporter_phone      TEXT NOT NULL,
    barangay_id         INTEGER REFERENCES barangay_zones(id),
    barangay_name       TEXT NOT NULL,
    notes               TEXT,

    device_lat          DOUBLE PRECISION,
    device_lng          DOUBLE PRECISION,
    pinned_lat          DOUBLE PRECISION NOT NULL,
    pinned_lng          DOUBLE PRECISION NOT NULL,

    -- PostGIS mirrors of the same coordinates (for spatial SQL)
    device_geom         GEOGRAPHY(POINT, 4326),
    pinned_geom         GEOGRAPHY(POINT, 4326) NOT NULL,

    -- Haversine / ST_Distance result in meters
    discrepancy_meters  DOUBLE PRECISION NOT NULL DEFAULT 0,

    spatial_status      TEXT NOT NULL CHECK (
                            spatial_status IN (
                                'Verified Spatial Alert',
                                'Unverified / Spatial Discrepancy'
                            )
                        ),
    lifecycle_status    TEXT NOT NULL DEFAULT 'Pending' CHECK (
                            lifecycle_status IN (
                                'Pending',
                                'Dispatched',
                                'Under Control',
                                'Fire Out',
                                'False Alarm'
                            )
                        ),
    updated_by          UUID REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_incidents_pinned
    ON incidents USING GIST (pinned_geom);
CREATE INDEX IF NOT EXISTS idx_incidents_created
    ON incidents (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_incidents_lifecycle
    ON incidents (lifecycle_status);

-- ---------------------------------------------------------------------------
-- Example spatial queries (not executed here; documented for operations)
-- ---------------------------------------------------------------------------
-- 1) Meter distance between device GPS and pinned marker (PostGIS equivalent
--    of the Node Haversine engine). geography uses the WGS84 spheroid:
--
--    SELECT ST_Distance(device_geom, pinned_geom) AS meters FROM incidents;
--
-- 2) Classify using the 100-meter operational threshold:
--
--    UPDATE incidents
--    SET spatial_status = CASE
--        WHEN ST_Distance(device_geom, pinned_geom) <= 100
--            THEN 'Verified Spatial Alert'
--        ELSE 'Unverified / Spatial Discrepancy'
--    END;
--
-- 3) Point-in-polygon: which barangay contains the pinned incident?
--
--    SELECT b.name
--    FROM barangay_zones b
--    JOIN incidents i ON ST_Covers(b.geom, i.pinned_geom)
--    WHERE i.id = :incident_id;
--
-- 4) Incidents within 2 km of the Kabatan fire station:
--
--    SELECT *
--    FROM incidents
--    WHERE ST_DWithin(
--        pinned_geom,
--        ST_SetSRID(ST_MakePoint(123.1697, 7.5164), 4326)::geography,
--        2000
--    );

-- ---------------------------------------------------------------------------
-- Seed: command staff (replace password_hash in production with bcrypt)
-- Default demo login is created by server.js if using the JSON store.
-- ---------------------------------------------------------------------------
INSERT INTO users (full_name, phone, username, password_hash, role, station_code)
VALUES
    ('VAS Fire Station Command', '0917-000-0000', 'firestaff',
     crypt('vas-bfp-2026', gen_salt('bf')), 'fire_personnel', 'VAS-BFP')
ON CONFLICT (username) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Seed: 14 barangays as schematic polygons around municipal center
-- (7.5164°N, 123.1697°E). Replace with official PSA/NAMRIA boundaries
-- for production. WKT rings are lon lat (PostGIS axis order for 4326 WKT).
-- ---------------------------------------------------------------------------
INSERT INTO barangay_zones (name, psgc_code, is_poblacion, population_2020, geom, centroid)
VALUES
(
    'Kabatan', '097348001', TRUE, 5059,
    ST_GeomFromText('POLYGON((123.1627 7.5094, 123.1767 7.5094, 123.1767 7.5234, 123.1627 7.5234, 123.1627 7.5094))', 4326)::geography,
    ST_GeomFromText('POINT(123.1697 7.5164)', 4326)::geography
),
(
    'Danan', '097348002', FALSE, 2609,
    ST_GeomFromText('POLYGON((123.1750 7.5150, 123.1890 7.5150, 123.1890 7.5290, 123.1750 7.5290, 123.1750 7.5150))', 4326)::geography,
    ST_GeomFromText('POINT(123.1820 7.5220)', 4326)::geography
),
(
    'Ambulon', '097348003', FALSE, 1302,
    ST_GeomFromText('POLYGON((123.1680 7.5210, 123.1820 7.5210, 123.1820 7.5350, 123.1680 7.5350, 123.1680 7.5210))', 4326)::geography,
    ST_GeomFromText('POINT(123.1750 7.5280)', 4326)::geography
),
(
    'Kapatagan', '097348004', FALSE, 1716,
    ST_GeomFromText('POLYGON((123.1780 7.5030, 123.1920 7.5030, 123.1920 7.5170, 123.1780 7.5170, 123.1780 7.5030))', 4326)::geography,
    ST_GeomFromText('POINT(123.1850 7.5100)', 4326)::geography
),
(
    'Limason', '097348005', FALSE, 1683,
    ST_GeomFromText('POLYGON((123.1480 7.5130, 123.1620 7.5130, 123.1620 7.5270, 123.1480 7.5270, 123.1480 7.5130))', 4326)::geography,
    ST_GeomFromText('POINT(123.1550 7.5200)', 4326)::geography
),
(
    'Lunib', '097348006', FALSE, 1714,
    ST_GeomFromText('POLYGON((123.1450 7.5010, 123.1590 7.5010, 123.1590 7.5150, 123.1450 7.5150, 123.1450 7.5010))', 4326)::geography,
    ST_GeomFromText('POINT(123.1520 7.5080)', 4326)::geography
),
(
    'Cogon', '097348007', FALSE, 1574,
    ST_GeomFromText('POLYGON((123.1530 7.4930, 123.1670 7.4930, 123.1670 7.5070, 123.1530 7.5070, 123.1530 7.4930))', 4326)::geography,
    ST_GeomFromText('POINT(123.1600 7.5000)', 4326)::geography
),
(
    'Bui-os', '097348008', FALSE, 1479,
    ST_GeomFromText('POLYGON((123.1650 7.4910, 123.1790 7.4910, 123.1790 7.5050, 123.1650 7.5050, 123.1650 7.4910))', 4326)::geography,
    ST_GeomFromText('POINT(123.1720 7.4980)', 4326)::geography
),
(
    'Maraya', '097348009', FALSE, 1430,
    ST_GeomFromText('POLYGON((123.1730 7.4850, 123.1870 7.4850, 123.1870 7.4990, 123.1730 7.4990, 123.1730 7.4850))', 4326)::geography,
    ST_GeomFromText('POINT(123.1800 7.4920)', 4326)::geography
),
(
    'Sagucan', '097348010', FALSE, 1272,
    ST_GeomFromText('POLYGON((123.1830 7.4980, 123.1970 7.4980, 123.1970 7.5120, 123.1830 7.5120, 123.1830 7.4980))', 4326)::geography,
    ST_GeomFromText('POINT(123.1900 7.5050)', 4326)::geography
),
(
    'Waling-waling', '097348011', FALSE, 1818,
    ST_GeomFromText('POLYGON((123.1530 7.5230, 123.1670 7.5230, 123.1670 7.5370, 123.1530 7.5370, 123.1530 7.5230))', 4326)::geography,
    ST_GeomFromText('POINT(123.1600 7.5300)', 4326)::geography
),
(
    'Lumbal', '097348012', FALSE, 1282,
    ST_GeomFromText('POLYGON((123.1610 7.5280, 123.1750 7.5280, 123.1750 7.5420, 123.1610 7.5420, 123.1610 7.5280))', 4326)::geography,
    ST_GeomFromText('POINT(123.1680 7.5350)', 4326)::geography
),
(
    'Linoguayan', '097348013', FALSE, 930,
    ST_GeomFromText('POLYGON((123.1410 7.5180, 123.1550 7.5180, 123.1550 7.5320, 123.1410 7.5320, 123.1410 7.5180))', 4326)::geography,
    ST_GeomFromText('POINT(123.1480 7.5250)', 4326)::geography
),
(
    'Maculay', '097348014', FALSE, 984,
    ST_GeomFromText('POLYGON((123.1330 7.5080, 123.1470 7.5080, 123.1470 7.5220, 123.1330 7.5220, 123.1330 7.5080))', 4326)::geography,
    ST_GeomFromText('POINT(123.1400 7.5150)', 4326)::geography
)
ON CONFLICT (name) DO NOTHING;
