-- PDERALSS - FINAL DATABASE SCRIPT  (drop everything, rebuild from scratch)
--
-- Run this whole file once in the Supabase SQL editor.
-- It runs as ONE transaction: if anything fails, nothing is changed.
--
--   1. Copies the user accounts aside (so you can still log in).
--   2. Drops every old table.
--   3. Creates the new schema: 37 Oton barangays, one placeholder
--      evacuation center per barangay, all tables, indexes, RLS.
--   4. Puts the user accounts back and removes the temporary copy.
--
-- After it runs: Social Workers have no barangay assigned (their old
-- Iloilo City address is not carried over). Set each one in
-- User Management. Barangay latitude/longitude are still empty, so map
-- pins appear only after you fill them in.

BEGIN;

-- STEP 1-2: keep accounts, drop old tables
CREATE TABLE public.users_keep AS
SELECT user_id, first_name, last_name, email, password, role, username, session_token
FROM public.users;

DROP TABLE IF EXISTS public.damage_assessments       CASCADE;
DROP TABLE IF EXISTS public.evacuation_evacuees      CASCADE;
DROP TABLE IF EXISTS public.evacuation_center_status CASCADE;
DROP TABLE IF EXISTS public.evacuation_center_info   CASCADE;
DROP TABLE IF EXISTS public.evacuation_centers       CASCADE;
DROP TABLE IF EXISTS public.casualties               CASCADE;
DROP TABLE IF EXISTS public.vehicle_requests         CASCADE;
DROP TABLE IF EXISTS public.relief_distributions     CASCADE;
DROP TABLE IF EXISTS public.family_composition       CASCADE;
DROP TABLE IF EXISTS public.household                CASCADE;
DROP TABLE IF EXISTS public.disaster_events          CASCADE;
DROP TABLE IF EXISTS public.users                    CASCADE;
DROP TABLE IF EXISTS public.barangays                CASCADE;


-- STEP 3: new schema
-- 1. barangays  [NEW]
-- One row per barangay. Replaces the free-text barangay column in 8
-- tables and the hardcoded lists MD_BARANGAYS (md.php), BARANGAYS
-- (users.php), HH_BARANGAYS (mdrrmo-officer/households.php) and
-- DASH_CENTER_DEFAULTS (dashboard.php).
-- name must match the strings already stored when backfilling old rows.
CREATE TABLE public.barangays (
    barangay_id SERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    latitude    DOUBLE PRECISION,
    longitude   DOUBLE PRECISION
);

-- The 37 barangays of Oton, Iloilo. Names are stored without a "Brgy."
-- prefix. lat/lng are filled in later:
--   UPDATE public.barangays SET latitude = ..., longitude = ... WHERE name = '...';
INSERT INTO public.barangays (name) VALUES
    ('Abilay Norte'),
    ('Abilay Sur'),
    ('Alegre'),
    ('Batuan Ilaud'),
    ('Batuan Ilaya'),
    ('Bita Norte'),
    ('Bita Sur'),
    ('Botong'),
    ('Buray'),
    ('Cabanbanan'),
    ('Caboloan Norte'),
    ('Caboloan Sur'),
    ('Cadinglian'),
    ('Cagbang'),
    ('Calam-isan'),
    ('Galang'),
    ('Lambuyao'),
    ('Mambog'),
    ('Pakiad'),
    ('Poblacion East'),
    ('Poblacion North'),
    ('Poblacion South'),
    ('Poblacion West'),
    ('Polo Maestra Bita'),
    ('Rizal'),
    ('Salngan'),
    ('Sambaludan'),
    ('San Antonio'),
    ('San Nicolas'),
    ('Santa Clara'),
    ('Santa Monica'),
    ('Santa Rita'),
    ('Tagbac Norte'),
    ('Tagbac Sur'),
    ('Trapiche'),
    ('Tuburan'),
    ('Turog-Turog');


-- 2. users
-- [CHANGED] address -> barangay_id. In the code, users.address is only
-- ever used as the Social Worker's assigned barangay. That is the
-- "Social Worker Assignment" in your entity list: one nullable FK on
-- the user, not a separate table. MDRRMO Officers leave it NULL.
-- [CHANGED] role gets a CHECK (only these two roles exist in access.php).
CREATE TABLE public.users (
    user_id       VARCHAR(50)  PRIMARY KEY,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL
                  CHECK (role IN ('MDRRMO Officer', 'Social Worker')),
    username      VARCHAR(100) NOT NULL UNIQUE,
    barangay_id   INTEGER REFERENCES public.barangays (barangay_id)
                  ON UPDATE CASCADE ON DELETE SET NULL,
    session_token VARCHAR(64)
);


-- 3. disaster_events
-- [CHANGED] created_by becomes a real FK; status gets its CHECK and is
-- created by the schema instead of db_ensure_disaster_event_status().
CREATE TABLE public.disaster_events (
    event_id    SERIAL PRIMARY KEY,
    event_name  VARCHAR(150) NOT NULL,
    type        VARCHAR(30),
    date        DATE,
    description TEXT,
    created_by  VARCHAR(50) REFERENCES public.users (user_id)
                ON UPDATE CASCADE ON DELETE SET NULL,
    status      VARCHAR(10) NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active', 'Passed'))
);


-- 4. household  (the family head's record)
-- [CHANGED] barangay -> barangay_id, created_by -> FK.
-- [NEW] updated_at: dashboard.php (dash_records) and the MDRRMO
--       households page already look for this column and silently skip
--       it today because it does not exist.
-- total_family_members / pwd_count / senior_citizens / age are kept:
-- the forms collect them, and the dashboards sum them.
CREATE TABLE public.household (
    household_id           SERIAL PRIMARY KEY,
    firstname              VARCHAR NOT NULL,
    middlename             VARCHAR,
    lastname               VARCHAR NOT NULL,
    nameextension          VARCHAR,
    date_of_birth          DATE,
    sex                    VARCHAR,
    civil_status           VARCHAR,
    total_family_members   INTEGER,
    is_4ps_member          VARCHAR,
    pwd_count              INTEGER,
    senior_citizens        INTEGER,
    barangay_id            INTEGER NOT NULL REFERENCES public.barangays (barangay_id)
                           ON UPDATE CASCADE ON DELETE RESTRICT,
    created_by             VARCHAR(50) REFERENCES public.users (user_id)
                           ON UPDATE CASCADE ON DELETE SET NULL,
    created_at             TIMESTAMP DEFAULT NOW(),
    updated_at             TIMESTAMP DEFAULT NOW(),
    age                    INTEGER,
    educational_attainment VARCHAR,
    occupation             VARCHAR,
    contact_no             VARCHAR
);
CREATE INDEX idx_household_barangay ON public.household (barangay_id);


-- 5. family_composition  (household members, excluding the head)
-- [CHANGED] household_id gets a real FK with ON DELETE CASCADE.
CREATE TABLE public.family_composition (
    composition_id         SERIAL PRIMARY KEY,
    household_id           INTEGER NOT NULL REFERENCES public.household (household_id)
                           ON UPDATE CASCADE ON DELETE CASCADE,
    first_name             VARCHAR NOT NULL,
    middle_name            VARCHAR,
    last_name              VARCHAR NOT NULL,
    age                    INTEGER,
    sex                    VARCHAR,
    civil_status           VARCHAR,
    relationship           VARCHAR,
    educational_attainment VARCHAR,
    occupation             VARCHAR,
    created_at             TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_family_household ON public.family_composition (household_id);


-- 6. evacuation_centers  [NEW - replaces evacuation_center_info]
-- Today evacuation_center_info is keyed by barangay text, is created
-- and seeded by dashboard.php at runtime, and has no coordinates, so
-- the map pins are hardcoded in mdrrmo-officer/evacuation-centers.php.
-- latitude/longitude are the "location for map purposes" (see fallback below).
-- UNIQUE (barangay_id) enforces the confirmed rule: one center per barangay.
CREATE TABLE public.evacuation_centers (
    center_id   SERIAL PRIMARY KEY,
    barangay_id INTEGER NOT NULL UNIQUE REFERENCES public.barangays (barangay_id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
    center_name VARCHAR(150) NOT NULL,
    capacity    INTEGER NOT NULL DEFAULT 600 CHECK (capacity >= 0),
    latitude    DOUBLE PRECISION,
    longitude   DOUBLE PRECISION
);

-- latitude/longitude are NULL by default and mean "same location as the
-- barangay". Read the pin position with:
--   COALESCE(c.latitude, b.latitude), COALESCE(c.longitude, b.longitude)
-- (c = evacuation_centers, b = barangays). Set them on a center only when
-- it differs from its barangay's location.

-- Placeholder center per barangay (one center per barangay is the rule),
-- using the same default label the code already shows. Rename them and set
-- real capacities later:
--   UPDATE public.evacuation_centers SET center_name = '...', capacity = ...
--   WHERE barangay_id = (SELECT barangay_id FROM public.barangays WHERE name = '...');
INSERT INTO public.evacuation_centers (barangay_id, center_name)
SELECT barangay_id, name || ' Evacuation Center'
FROM public.barangays
ORDER BY barangay_id;


-- 7. evacuation_center_status  (a center's availability per event)
-- [CHANGED] key is (event_id, center_id) instead of (event_id, barangay).
-- This table is required: it holds the Social Worker's Available/Full
-- dropdown, which blocks adding evacuees and feeds the MDRRMO map.
CREATE TABLE public.evacuation_center_status (
    event_id   INTEGER NOT NULL REFERENCES public.disaster_events (event_id)
               ON UPDATE CASCADE ON DELETE RESTRICT,
    center_id  INTEGER NOT NULL REFERENCES public.evacuation_centers (center_id)
               ON UPDATE CASCADE ON DELETE RESTRICT,
    status     VARCHAR(20) NOT NULL DEFAULT 'Available'
               CHECK (status IN ('Available', 'Occupied', 'Near Capacity', 'Full')),
    updated_by VARCHAR(50) REFERENCES public.users (user_id)
               ON UPDATE CASCADE ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (event_id, center_id)
);


-- 8. evacuation_evacuees  (evacuee records)
-- [CHANGED] barangay (text) removed: the center already implies it.
-- [CHANGED] household_no renamed people_count. The code stores the
--           NUMBER OF PEOPLE in it and sums it as "Total People".
-- [CHANGED] event_id / center_id / household_id / added_by are now FKs.
-- name is kept on purpose: walk-ins have no household_id, and the name
-- keeps the record readable if the household is later deleted.
CREATE TABLE public.evacuation_evacuees (
    evacuee_id   SERIAL PRIMARY KEY,
    event_id     INTEGER NOT NULL REFERENCES public.disaster_events (event_id)
                 ON UPDATE CASCADE ON DELETE RESTRICT,
    center_id    INTEGER NOT NULL REFERENCES public.evacuation_centers (center_id)
                 ON UPDATE CASCADE ON DELETE RESTRICT,
    household_id INTEGER REFERENCES public.household (household_id)
                 ON UPDATE CASCADE ON DELETE SET NULL,
    name         VARCHAR NOT NULL,
    people_count INTEGER NOT NULL DEFAULT 1 CHECK (people_count >= 1),
    added_by     VARCHAR(50) REFERENCES public.users (user_id)
                 ON UPDATE CASCADE ON DELETE SET NULL,
    created_at   TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_evacuees_event_center ON public.evacuation_evacuees (event_id, center_id);
-- A registered household can only be checked in once per event.
CREATE UNIQUE INDEX uq_evacuees_event_household
    ON public.evacuation_evacuees (event_id, household_id)
    WHERE household_id IS NOT NULL;


-- 9. casualties
-- [CHANGED] barangay (text) -> barangay_id; added_by -> FK.
-- name stays free text: the system records casualties by name and does
-- not link them to household members.
CREATE TABLE public.casualties (
    casualty_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    event_id    INTEGER NOT NULL REFERENCES public.disaster_events (event_id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
    barangay_id INTEGER NOT NULL REFERENCES public.barangays (barangay_id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
    name        VARCHAR(150) NOT NULL,
    type        VARCHAR(20)  NOT NULL CHECK (type IN ('Fatality', 'Missing', 'Injured')),
    severity    VARCHAR(20)  NOT NULL CHECK (severity IN ('Minor', 'Moderate', 'Critical')),
    date        DATE NOT NULL,
    notes       TEXT,
    added_by    VARCHAR(50) REFERENCES public.users (user_id)
                ON UPDATE CASCADE ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_casualties_event_barangay ON public.casualties (event_id, barangay_id);


-- 10. damage_assessments  (proof photo + record, houses only)
-- [NEW]     event_id. The MDRRMO pages open this from inside a disaster
--           event and show the event name, but the table has no link to
--           an event, so every event shows every assessment.
-- [CHANGED] household_id is now required (the submit code already
--           refuses an assessment without a household).
-- [REMOVED] asset_type: assessments are houses only, so it has one value.
-- [REMOVED] barangay: always equals household.barangay_id (da_submit
--           checks this), so read it through the household join.
-- [REMOVED] status: it never left 'Under Review' (no verify/reject action
--           exists). Remove the 'Under Review' alert in dashboard.php too.
-- [CHANGED] photo_path is required: the photo is the proof.
-- [CHANGED] submitted_by -> FK.
-- Everything else (GPS, EXIF, hashes, signature) is unchanged.
CREATE TABLE public.damage_assessments (
    assessment_id   BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    reference_code  VARCHAR(30) NOT NULL UNIQUE,
    event_id        INTEGER NOT NULL REFERENCES public.disaster_events (event_id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
    household_id    INTEGER NOT NULL REFERENCES public.household (household_id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
    reporter        VARCHAR(150) NOT NULL,
    damage_status   VARCHAR(30) NOT NULL
                    CHECK (damage_status IN ('Fully Damaged', 'Partially Damaged', 'Minor Damage')),
    description     TEXT,
    latitude        DOUBLE PRECISION,
    longitude       DOUBLE PRECISION,
    gps_accuracy_m  REAL,
    location_source VARCHAR(20),
    location_flags  VARCHAR(100),
    address         TEXT,
    exif_lat        DOUBLE PRECISION,
    exif_lng        DOUBLE PRECISION,
    exif_taken_at   TIMESTAMPTZ,
    photo_path      TEXT NOT NULL,
    original_path   TEXT,
    original_sha256 CHAR(64),
    photo_sha256    CHAR(64),
    signature       CHAR(64),
    captured_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    submitted_by    VARCHAR(50) REFERENCES public.users (user_id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_damage_event     ON public.damage_assessments (event_id);
CREATE INDEX idx_damage_household ON public.damage_assessments (household_id);


-- 11. vehicle_requests
-- [CHANGED] barangay -> barangay_id; requested_by / reviewed_by -> FKs.
-- assigned_vehicle stays free text (the clash check compares the text).
CREATE TABLE public.vehicle_requests (
    request_id       BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    request_code     VARCHAR(20) UNIQUE,
    barangay_id      INTEGER NOT NULL REFERENCES public.barangays (barangay_id)
                     ON UPDATE CASCADE ON DELETE RESTRICT,
    purpose          VARCHAR(60) NOT NULL,
    passengers       INTEGER NOT NULL CHECK (passengers >= 0),
    urgency          VARCHAR(10) NOT NULL CHECK (urgency IN ('High', 'Medium', 'Low')),
    requested_date   DATE NOT NULL,
    notes            TEXT,
    status           VARCHAR(12) NOT NULL DEFAULT 'Pending'
                     CHECK (status IN ('Pending', 'Approved', 'Scheduled', 'Completed', 'Rejected')),
    assigned_vehicle VARCHAR(100),
    requested_by     VARCHAR(50) NOT NULL REFERENCES public.users (user_id)
                     ON UPDATE CASCADE ON DELETE RESTRICT,
    reviewed_by      VARCHAR(50) REFERENCES public.users (user_id)
                     ON UPDATE CASCADE ON DELETE SET NULL,
    reviewed_at      TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_vehicle_barangay_status ON public.vehicle_requests (barangay_id, status);


-- 12. relief_distributions
-- [CHANGED] barangay -> barangay_id; recorded_by -> FK.
CREATE TABLE public.relief_distributions (
    distribution_id   BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    distribution_code VARCHAR(20) UNIQUE,
    barangay_id       INTEGER NOT NULL REFERENCES public.barangays (barangay_id)
                      ON UPDATE CASCADE ON DELETE RESTRICT,
    item              VARCHAR(50) NOT NULL
                      CHECK (item IN ('Food Packs', 'Water (Liters)', 'Medicine', 'Hygiene Kits')),
    quantity          INTEGER NOT NULL CHECK (quantity > 0),
    date_released     DATE NOT NULL,
    status            VARCHAR(12) NOT NULL DEFAULT 'Pending'
                      CHECK (status IN ('Pending', 'In Transit', 'Completed')),
    received_by       VARCHAR(150),
    recorded_by       VARCHAR(50) REFERENCES public.users (user_id)
                      ON UPDATE CASCADE ON DELETE SET NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_relief_barangay ON public.relief_distributions (barangay_id);


-- Row level security. The PHP app connects as the postgres role (which
-- bypasses RLS); enabling RLS with no policies means Supabase's public
-- REST API can read none of these tables. users (password hashes) and
-- household (personal data) matter most.
ALTER TABLE public.barangays                ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.users                    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.disaster_events          ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.household                ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.family_composition       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.evacuation_centers       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.evacuation_center_status ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.evacuation_evacuees      ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.casualties               ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.damage_assessments       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.vehicle_requests         ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.relief_distributions     ENABLE ROW LEVEL SECURITY;


-- STEP 4: restore accounts
INSERT INTO public.users
    (user_id, first_name, last_name, email, password, role, username, session_token)
SELECT user_id, first_name, last_name, email, password, role, username, session_token
FROM public.users_keep;

DROP TABLE public.users_keep;

COMMIT;

-- Quick checks (run separately):
--   SELECT COUNT(*) FROM public.barangays;            -- 37
--   SELECT COUNT(*) FROM public.evacuation_centers;   -- 37
--   SELECT user_id, role, barangay_id FROM public.users;
