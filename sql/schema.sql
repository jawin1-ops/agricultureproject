-- ============================================================================
--  Sproutly  |  schema_v2.sql  |  Supabase (PostgreSQL) migration
--  Phase 1: roles/users, 7 lookup tables, 5 core tables, activity log.
--
--  * Single transaction (BEGIN ... COMMIT): any error rolls everything back.
--  * Idempotent: safe to run more than once.
--  * Non-destructive: no DROP TABLE, no TRUNCATE, no DELETE. Existing rows are
--    kept. Legacy columns (barangay_id, address_detail, selling_price_per_kg on
--    crops, etc.) are left in place.
--  * The change_requests table is NOT dropped (existing data is preserved) but
--    nothing in Sproutly uses it any more. Retire it manually when ready.
-- ============================================================================

BEGIN;

-- ----------------------------------------------------------------------------
-- 0. Legacy-name reconciliation (rename only, rows untouched)
--    Older code used  planting_statuses  and  harvest_remarks.remark_description.
-- ----------------------------------------------------------------------------
DO $$
BEGIN
    IF to_regclass('public.planting_statuses') IS NOT NULL
       AND to_regclass('public.planting_status') IS NULL THEN
        ALTER TABLE public.planting_statuses RENAME TO planting_status;
    END IF;

    IF to_regclass('public.harvest_remarks') IS NOT NULL
       AND EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'public' AND table_name = 'harvest_remarks'
                     AND column_name = 'remark_description')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'public' AND table_name = 'harvest_remarks'
                     AND column_name = 'description') THEN
        ALTER TABLE public.harvest_remarks RENAME COLUMN remark_description TO description;
    END IF;
END $$;

-- ----------------------------------------------------------------------------
-- 1. Roles & users
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    role_id   SMALLINT PRIMARY KEY,
    role_name VARCHAR(64) NOT NULL
);

INSERT INTO roles (role_id, role_name) VALUES
    (1, 'Administrator'),
    (2, 'Staff')
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS app_users (
    user_id       SERIAL PRIMARY KEY,
    full_name     VARCHAR(150) NOT NULL DEFAULT '',
    username      VARCHAR(80)  NOT NULL,
    password_hash TEXT         NOT NULL DEFAULT '',
    role_id       INT,
    is_deleted    BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ  DEFAULT NOW(),
    updated_at    TIMESTAMPTZ  DEFAULT NOW()
);

ALTER TABLE app_users ADD COLUMN IF NOT EXISTS full_name     VARCHAR(150) NOT NULL DEFAULT '';
ALTER TABLE app_users ADD COLUMN IF NOT EXISTS password_hash TEXT         NOT NULL DEFAULT '';
ALTER TABLE app_users ADD COLUMN IF NOT EXISTS role_id       INT;
ALTER TABLE app_users ADD COLUMN IF NOT EXISTS is_deleted    BOOLEAN      NOT NULL DEFAULT FALSE;
ALTER TABLE app_users ADD COLUMN IF NOT EXISTS created_at    TIMESTAMPTZ  DEFAULT NOW();
ALTER TABLE app_users ADD COLUMN IF NOT EXISTS updated_at    TIMESTAMPTZ  DEFAULT NOW();

-- Existing users get their username as a display name until edited.
UPDATE app_users SET full_name = username WHERE full_name = '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_app_users_username ON app_users (username);

-- ----------------------------------------------------------------------------
-- 2. Lookup tables (7)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS soil_types (
    soil_type_id SERIAL PRIMARY KEY,
    description  VARCHAR(100) NOT NULL,
    is_deleted   BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS irrigation_types (
    irrigation_type_id SERIAL PRIMARY KEY,
    description        VARCHAR(100) NOT NULL,
    is_deleted         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMPTZ DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS crop_categories (
    category_id SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS seasons (
    season_id   SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS planting_status (
    status_id   SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS quality_grades (
    grade_id    SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS harvest_remarks (
    remark_id   SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);

-- Bring pre-existing lookup tables up to the v2 shape.
ALTER TABLE soil_types       ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE soil_types       ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE irrigation_types ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE irrigation_types ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE crop_categories  ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE crop_categories  ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE seasons          ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE seasons          ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE planting_status  ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE planting_status  ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE quality_grades   ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE quality_grades   ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE harvest_remarks  ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE harvest_remarks  ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW();

ALTER TABLE soil_types       ALTER COLUMN description SET NOT NULL;
ALTER TABLE irrigation_types ALTER COLUMN description SET NOT NULL;
ALTER TABLE crop_categories  ALTER COLUMN description SET NOT NULL;
ALTER TABLE seasons          ALTER COLUMN description SET NOT NULL;
ALTER TABLE planting_status  ALTER COLUMN description SET NOT NULL;
ALTER TABLE quality_grades   ALTER COLUMN description SET NOT NULL;
ALTER TABLE harvest_remarks  ALTER COLUMN description SET NOT NULL;

-- description must be UNIQUE (also what makes the seed upserts safe to re-run)
CREATE UNIQUE INDEX IF NOT EXISTS uq_soil_types_description       ON soil_types (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_irrigation_types_description ON irrigation_types (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_crop_categories_description  ON crop_categories (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_seasons_description          ON seasons (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_planting_status_description  ON planting_status (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_quality_grades_description   ON quality_grades (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_harvest_remarks_description  ON harvest_remarks (description);

-- Older lookup tables may have an ID column with no auto-increment. Give any
-- such column a sequence (continuing after the current max ID) so inserts work.
DO $$
DECLARE
    r   RECORD;
    seq TEXT;
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('soil_types',       'soil_type_id'),
            ('irrigation_types', 'irrigation_type_id'),
            ('crop_categories',  'category_id'),
            ('seasons',          'season_id'),
            ('planting_status',  'status_id'),
            ('quality_grades',   'grade_id'),
            ('harvest_remarks',  'remark_id')
        ) AS v(tbl, col)
    LOOP
        IF EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = r.tbl
                      AND column_name = r.col
                      AND column_default IS NULL
                      AND is_identity = 'NO') THEN
            seq := r.tbl || '_' || r.col || '_seq';
            EXECUTE format('CREATE SEQUENCE IF NOT EXISTS public.%I', seq);
            EXECUTE format('SELECT setval(%L, COALESCE((SELECT MAX(%I) FROM public.%I), 0) + 1, false)',
                           'public.' || seq, r.col, r.tbl);
            EXECUTE format('ALTER TABLE public.%I ALTER COLUMN %I SET DEFAULT nextval(%L)',
                           r.tbl, r.col, 'public.' || seq);
            EXECUTE format('ALTER SEQUENCE public.%I OWNED BY public.%I.%I', seq, r.tbl, r.col);
        END IF;
    END LOOP;
END $$;

-- Seed data
INSERT INTO soil_types (description) VALUES
    ('Loam'), ('Clay'), ('Sandy'), ('Silt'), ('Peat'), ('Chalky')
ON CONFLICT DO NOTHING;

INSERT INTO irrigation_types (description) VALUES
    ('Rainfed'), ('Drip Irrigation'), ('Sprinkler'), ('Canal / Surface'), ('Pump / Well')
ON CONFLICT DO NOTHING;

INSERT INTO crop_categories (description) VALUES
    ('Grains'), ('Vegetables'), ('Fruits'), ('Root Crops'), ('Legumes'), ('Cash Crops')
ON CONFLICT DO NOTHING;

INSERT INTO seasons (description) VALUES
    ('Dry Season'), ('Wet Season'), ('All-Year')
ON CONFLICT DO NOTHING;

-- Fixed IDs: 1 = Planted, 2 = Growing, 3 = Harvested
INSERT INTO planting_status (status_id, description) VALUES
    (1, 'Planted'), (2, 'Growing'), (3, 'Harvested')
ON CONFLICT DO NOTHING;

-- Keep the SERIAL sequence ahead of the explicitly inserted IDs
SELECT setval(
    pg_get_serial_sequence('planting_status', 'status_id'),
    GREATEST((SELECT COALESCE(MAX(status_id), 1) FROM planting_status), 3)
);

INSERT INTO quality_grades (description) VALUES
    ('Grade A - Premium'), ('Grade B - Standard'), ('Grade C - Processing')
ON CONFLICT DO NOTHING;

INSERT INTO harvest_remarks (description) VALUES
    ('Good Yield'), ('Normal Harvest'), ('Weather Affected'), ('Pest / Disease Impact')
ON CONFLICT DO NOTHING;

-- ----------------------------------------------------------------------------
-- 3. Core operational tables (5): CREATE if missing, then ALTER to add columns
--    FKs are added uniformly in section 4.
-- ----------------------------------------------------------------------------

-- 3.1 farmers
CREATE TABLE IF NOT EXISTS farmers (
    farmer_id         SERIAL PRIMARY KEY,
    first_name        VARCHAR(100) NOT NULL,
    last_name         VARCHAR(100) NOT NULL,
    contact_no        VARCHAR(30),
    address           TEXT,
    registration_date DATE DEFAULT CURRENT_DATE,
    is_deleted        BOOLEAN NOT NULL DEFAULT FALSE,
    created_at        TIMESTAMPTZ DEFAULT NOW(),
    updated_at        TIMESTAMPTZ DEFAULT NOW(),
    created_by        INT,
    updated_by        INT
);
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS contact_no        VARCHAR(30);
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS address           TEXT;
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS registration_date DATE;
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS is_deleted        BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS created_at        TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS updated_at        TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS created_by        INT;
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS updated_by        INT;

UPDATE farmers SET registration_date = COALESCE(created_at::date, CURRENT_DATE)
 WHERE registration_date IS NULL;
ALTER TABLE farmers ALTER COLUMN registration_date SET DEFAULT CURRENT_DATE;

-- 3.2 farms
CREATE TABLE IF NOT EXISTS farms (
    farm_id            SERIAL PRIMARY KEY,
    farmer_id          INT NOT NULL,
    farm_name          VARCHAR(150) NOT NULL,
    location           VARCHAR(255),
    area_hectares      NUMERIC(10,2),
    soil_type_id       INT,
    irrigation_type_id INT,
    is_deleted         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMPTZ DEFAULT NOW(),
    updated_at         TIMESTAMPTZ DEFAULT NOW(),
    created_by         INT,
    updated_by         INT
);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS location           VARCHAR(255);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS area_hectares      NUMERIC(10,2);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS soil_type_id       INT;
ALTER TABLE farms ADD COLUMN IF NOT EXISTS irrigation_type_id INT;
ALTER TABLE farms ADD COLUMN IF NOT EXISTS is_deleted         BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE farms ADD COLUMN IF NOT EXISTS created_at         TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farms ADD COLUMN IF NOT EXISTS updated_at         TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farms ADD COLUMN IF NOT EXISTS created_by         INT;
ALTER TABLE farms ADD COLUMN IF NOT EXISTS updated_by         INT;

-- 3.3 crops
CREATE TABLE IF NOT EXISTS crops (
    crop_id      SERIAL PRIMARY KEY,
    crop_name    VARCHAR(150) NOT NULL,
    variety      VARCHAR(100),
    category_id  INT,
    growing_days INT,
    season_id    INT,
    is_deleted   BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ DEFAULT NOW(),
    updated_at   TIMESTAMPTZ DEFAULT NOW(),
    created_by   INT,
    updated_by   INT
);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS variety      VARCHAR(100);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS category_id  INT;
ALTER TABLE crops ADD COLUMN IF NOT EXISTS growing_days INT;
ALTER TABLE crops ADD COLUMN IF NOT EXISTS season_id    INT;
ALTER TABLE crops ADD COLUMN IF NOT EXISTS is_deleted   BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE crops ADD COLUMN IF NOT EXISTS created_at   TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE crops ADD COLUMN IF NOT EXISTS updated_at   TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE crops ADD COLUMN IF NOT EXISTS created_by   INT;
ALTER TABLE crops ADD COLUMN IF NOT EXISTS updated_by   INT;

-- 3.4 plantings
CREATE TABLE IF NOT EXISTS plantings (
    planting_id           SERIAL PRIMARY KEY,
    farm_id               INT NOT NULL,
    crop_id               INT NOT NULL,
    planting_date         DATE NOT NULL,
    area_planted_hectares NUMERIC(10,2),
    seed_quantity_kg      NUMERIC(12,2),
    expected_harvest_date DATE,
    status_id             INT,
    is_deleted            BOOLEAN NOT NULL DEFAULT FALSE,
    created_at            TIMESTAMPTZ DEFAULT NOW(),
    updated_at            TIMESTAMPTZ DEFAULT NOW(),
    created_by            INT,
    updated_by            INT
);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS area_planted_hectares NUMERIC(10,2);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS seed_quantity_kg      NUMERIC(12,2);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS expected_harvest_date DATE;
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS status_id             INT;
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS is_deleted            BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS created_at            TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS updated_at            TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS created_by            INT;
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS updated_by            INT;

-- 3.5 harvests
CREATE TABLE IF NOT EXISTS harvests (
    harvest_id           SERIAL PRIMARY KEY,
    planting_id          INT NOT NULL,
    harvest_date         DATE NOT NULL,
    quantity_kg          NUMERIC(12,2),
    selling_price_per_kg NUMERIC(12,2),
    grade_id             INT,
    remark_id            INT,
    is_deleted           BOOLEAN NOT NULL DEFAULT FALSE,
    created_at           TIMESTAMPTZ DEFAULT NOW(),
    updated_at           TIMESTAMPTZ DEFAULT NOW(),
    created_by           INT,
    updated_by           INT
);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS quantity_kg          NUMERIC(12,2);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS selling_price_per_kg NUMERIC(12,2);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS grade_id             INT;
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS remark_id            INT;
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS is_deleted           BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS created_at           TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS updated_at           TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS created_by           INT;
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS updated_by           INT;

-- Backfill the new text columns from the legacy barangay-based columns, if present.
DO $$
BEGIN
    IF to_regclass('public.barangays') IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'farmers'
               AND column_name IN ('barangay_id', 'address_detail')) = 2 THEN
            EXECUTE $q$
                UPDATE farmers f
                   SET address = NULLIF(concat_ws(', ',
                           NULLIF(f.address_detail, ''),
                           (SELECT concat_ws(', ', b.barangay_name, b.city_municipality, b.province)
                              FROM barangays b WHERE b.barangay_id = f.barangay_id)), '')
                 WHERE f.address IS NULL
            $q$;
        END IF;

        IF EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = 'farms'
                      AND column_name = 'barangay_id') THEN
            EXECUTE $q$
                UPDATE farms fm
                   SET location = NULLIF((SELECT concat_ws(', ', b.barangay_name, b.city_municipality, b.province)
                                            FROM barangays b WHERE b.barangay_id = fm.barangay_id), '')
                 WHERE fm.location IS NULL
            $q$;
        END IF;
    ELSIF EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'public' AND table_name = 'farmers'
                     AND column_name = 'address_detail') THEN
        EXECUTE $q$
            UPDATE farmers SET address = NULLIF(address_detail, '') WHERE address IS NULL
        $q$;
    END IF;
END $$;

-- Legacy NOT NULL columns that the v2 schema no longer collects. They are
-- relaxed (never dropped) so new rows can be inserted without them.
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('farmers', 'barangay_id'),
            ('farms',   'barangay_id'),
            ('crops',   'selling_price_per_kg')
        ) AS v(tbl, col)
    LOOP
        IF EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = r.tbl
                      AND column_name = r.col AND is_nullable = 'NO') THEN
            EXECUTE format('ALTER TABLE public.%I ALTER COLUMN %I DROP NOT NULL', r.tbl, r.col);
        END IF;
    END LOOP;

    -- harvests.selling_price_per_kg is new: seed it from the crop's old price
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'crops'
                  AND column_name = 'selling_price_per_kg') THEN
        EXECUTE $q$
            UPDATE harvests h
               SET selling_price_per_kg = c.selling_price_per_kg
              FROM plantings p
              JOIN crops c ON c.crop_id = p.crop_id
             WHERE h.planting_id = p.planting_id
               AND h.selling_price_per_kg IS NULL
        $q$;
    END IF;
END $$;

-- ----------------------------------------------------------------------------
-- 4. Foreign keys + CHECK constraint
--    Added only where no FK already exists on that column. NOT VALID means
--    existing rows are never rejected or touched; new/updated rows are enforced.
--    (Run  ALTER TABLE <t> VALIDATE CONSTRAINT <name>;  later if you want.)
-- ----------------------------------------------------------------------------
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('app_users', 'role_id',            'roles',  'role_id'),
            ('farmers',     'created_by',         'app_users',      'user_id'),
            ('farmers',     'updated_by',         'app_users',      'user_id'),
            ('farms',       'created_by',         'app_users',      'user_id'),
            ('farms',       'updated_by',         'app_users',      'user_id'),
            ('crops',       'created_by',         'app_users',      'user_id'),
            ('crops',       'updated_by',         'app_users',      'user_id'),
            ('plantings',   'created_by',         'app_users',      'user_id'),
            ('plantings',   'updated_by',         'app_users',      'user_id'),
            ('harvests',    'created_by',         'app_users',      'user_id'),
            ('harvests',    'updated_by',         'app_users',      'user_id'),
            ('farms',       'farmer_id',          'farmers',          'farmer_id'),
            ('farms',       'soil_type_id',       'soil_types',       'soil_type_id'),
            ('farms',       'irrigation_type_id', 'irrigation_types', 'irrigation_type_id'),
            ('crops',       'category_id',        'crop_categories',  'category_id'),
            ('crops',       'season_id',          'seasons',          'season_id'),
            ('plantings',   'farm_id',            'farms',            'farm_id'),
            ('plantings',   'crop_id',            'crops',            'crop_id'),
            ('plantings',   'status_id',          'planting_status',  'status_id'),
            ('harvests',    'planting_id',        'plantings',        'planting_id'),
            ('harvests',    'grade_id',           'quality_grades',   'grade_id'),
            ('harvests',    'remark_id',          'harvest_remarks',  'remark_id')
        ) AS v(tbl, col, ref_tbl, ref_col)
    LOOP
        IF NOT EXISTS (
            SELECT 1
              FROM pg_constraint c
              JOIN pg_attribute  a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
             WHERE c.contype  = 'f'
               AND c.conrelid = ('public.' || r.tbl)::regclass
               AND a.attname  = r.col
        ) THEN
            EXECUTE format(
                'ALTER TABLE public.%I ADD CONSTRAINT %I FOREIGN KEY (%I) REFERENCES public.%I (%I) NOT VALID',
                r.tbl, 'fk_' || r.tbl || '_' || r.col, r.col, r.ref_tbl, r.ref_col);
        END IF;
    END LOOP;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'chk_plantings_harvest_after_planting'
                      AND conrelid = 'public.plantings'::regclass) THEN
        ALTER TABLE public.plantings
            ADD CONSTRAINT chk_plantings_harvest_after_planting
            CHECK (expected_harvest_date > planting_date) NOT VALID;
    END IF;
END $$;

-- ----------------------------------------------------------------------------
-- 5. Activity log (audit trail)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    log_id       SERIAL PRIMARY KEY,
    user_id      INT REFERENCES app_users (user_id) ON DELETE SET NULL,
    action_type  VARCHAR(20) NOT NULL
                 CHECK (action_type IN ('CREATE','UPDATE','SOFT_DELETE','RESTORE','LOGIN')),
    target_table VARCHAR(64) NOT NULL,
    record_id    INT,
    description  TEXT NOT NULL,
    old_data     JSONB,
    new_data     JSONB,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------------------
-- 6. Indexes
-- ----------------------------------------------------------------------------
-- is_deleted
CREATE INDEX IF NOT EXISTS idx_app_users_is_deleted      ON app_users (is_deleted);
CREATE INDEX IF NOT EXISTS idx_soil_types_is_deleted       ON soil_types (is_deleted);
CREATE INDEX IF NOT EXISTS idx_irrigation_types_is_deleted ON irrigation_types (is_deleted);
CREATE INDEX IF NOT EXISTS idx_crop_categories_is_deleted  ON crop_categories (is_deleted);
CREATE INDEX IF NOT EXISTS idx_seasons_is_deleted          ON seasons (is_deleted);
CREATE INDEX IF NOT EXISTS idx_planting_status_is_deleted  ON planting_status (is_deleted);
CREATE INDEX IF NOT EXISTS idx_quality_grades_is_deleted   ON quality_grades (is_deleted);
CREATE INDEX IF NOT EXISTS idx_harvest_remarks_is_deleted  ON harvest_remarks (is_deleted);
CREATE INDEX IF NOT EXISTS idx_farmers_is_deleted          ON farmers (is_deleted);
CREATE INDEX IF NOT EXISTS idx_farms_is_deleted            ON farms (is_deleted);
CREATE INDEX IF NOT EXISTS idx_crops_is_deleted            ON crops (is_deleted);
CREATE INDEX IF NOT EXISTS idx_plantings_is_deleted        ON plantings (is_deleted);
CREATE INDEX IF NOT EXISTS idx_harvests_is_deleted         ON harvests (is_deleted);

-- foreign keys
CREATE INDEX IF NOT EXISTS idx_app_users_role_id         ON app_users (role_id);
CREATE INDEX IF NOT EXISTS idx_farmers_created_by          ON farmers (created_by);
CREATE INDEX IF NOT EXISTS idx_farmers_updated_by          ON farmers (updated_by);
CREATE INDEX IF NOT EXISTS idx_farms_farmer_id             ON farms (farmer_id);
CREATE INDEX IF NOT EXISTS idx_farms_soil_type_id          ON farms (soil_type_id);
CREATE INDEX IF NOT EXISTS idx_farms_irrigation_type_id    ON farms (irrigation_type_id);
CREATE INDEX IF NOT EXISTS idx_farms_created_by            ON farms (created_by);
CREATE INDEX IF NOT EXISTS idx_farms_updated_by            ON farms (updated_by);
CREATE INDEX IF NOT EXISTS idx_crops_category_id           ON crops (category_id);
CREATE INDEX IF NOT EXISTS idx_crops_season_id             ON crops (season_id);
CREATE INDEX IF NOT EXISTS idx_crops_created_by            ON crops (created_by);
CREATE INDEX IF NOT EXISTS idx_crops_updated_by            ON crops (updated_by);
CREATE INDEX IF NOT EXISTS idx_plantings_farm_id           ON plantings (farm_id);
CREATE INDEX IF NOT EXISTS idx_plantings_crop_id           ON plantings (crop_id);
CREATE INDEX IF NOT EXISTS idx_plantings_status_id         ON plantings (status_id);
CREATE INDEX IF NOT EXISTS idx_plantings_created_by        ON plantings (created_by);
CREATE INDEX IF NOT EXISTS idx_plantings_updated_by        ON plantings (updated_by);
CREATE INDEX IF NOT EXISTS idx_harvests_planting_id        ON harvests (planting_id);
CREATE INDEX IF NOT EXISTS idx_harvests_grade_id           ON harvests (grade_id);
CREATE INDEX IF NOT EXISTS idx_harvests_remark_id          ON harvests (remark_id);
CREATE INDEX IF NOT EXISTS idx_harvests_created_by         ON harvests (created_by);
CREATE INDEX IF NOT EXISTS idx_harvests_updated_by         ON harvests (updated_by);

-- activity log
CREATE INDEX IF NOT EXISTS idx_activity_log_created_at     ON activity_log (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_log_user_id        ON activity_log (user_id);
CREATE INDEX IF NOT EXISTS idx_activity_log_target         ON activity_log (target_table, record_id);

-- ----------------------------------------------------------------------------
-- 7. Lock the tables away from Supabase's public Data API (anon / authenticated)
--    Sproutly talks to Postgres directly over PDO as the `postgres` role, which
--    bypasses RLS, so the app is unaffected. With RLS on and no policies, the
--    REST API can no longer read these tables (e.g. app_users.password_hash).
-- ----------------------------------------------------------------------------
ALTER TABLE roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE app_users      ENABLE ROW LEVEL SECURITY;
ALTER TABLE soil_types       ENABLE ROW LEVEL SECURITY;
ALTER TABLE irrigation_types ENABLE ROW LEVEL SECURITY;
ALTER TABLE crop_categories  ENABLE ROW LEVEL SECURITY;
ALTER TABLE seasons          ENABLE ROW LEVEL SECURITY;
ALTER TABLE planting_status  ENABLE ROW LEVEL SECURITY;
ALTER TABLE quality_grades   ENABLE ROW LEVEL SECURITY;
ALTER TABLE harvest_remarks  ENABLE ROW LEVEL SECURITY;
ALTER TABLE farmers          ENABLE ROW LEVEL SECURITY;
ALTER TABLE farms            ENABLE ROW LEVEL SECURITY;
ALTER TABLE crops            ENABLE ROW LEVEL SECURITY;
ALTER TABLE plantings        ENABLE ROW LEVEL SECURITY;
ALTER TABLE harvests         ENABLE ROW LEVEL SECURITY;
ALTER TABLE activity_log     ENABLE ROW LEVEL SECURITY;

COMMIT;
