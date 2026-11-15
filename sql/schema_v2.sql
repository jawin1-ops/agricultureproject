-- ============================================================================
--  Sproutly  |  sql/schema_v2.sql  |  Supabase (PostgreSQL) Migration + Seeds
--  Phase 1: Roles, Users, 7 Lookup Tables, 5 Core Operational Tables, Audit Trail.
-- ============================================================================

BEGIN;

-- ----------------------------------------------------------------------------
-- 0. Pre-migration Name Normalization (Safe RENAMEs if legacy tables/columns exist)
-- ----------------------------------------------------------------------------
DO $$
BEGIN
    -- Legacy lookup table: planting_statuses -> planting_status
    IF to_regclass('public.planting_statuses') IS NOT NULL
       AND to_regclass('public.planting_status') IS NULL THEN
        ALTER TABLE public.planting_statuses RENAME TO planting_status;
    END IF;

    -- Legacy lookup column: harvest_remarks.remark_description -> description
    IF to_regclass('public.harvest_remarks') IS NOT NULL
       AND EXISTS (
           SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'harvest_remarks'
              AND column_name = 'remark_description'
       )
       AND NOT EXISTS (
           SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'harvest_remarks'
              AND column_name = 'description'
       ) THEN
        ALTER TABLE public.harvest_remarks RENAME COLUMN remark_description TO description;
    END IF;

    -- Legacy user table alias reconciliation: app_users -> "system_user"
    IF to_regclass('public.app_users') IS NOT NULL
       AND to_regclass('public."system_user"') IS NULL THEN
        ALTER TABLE public.app_users RENAME TO "system_user";
    END IF;

    -- Legacy role table alias reconciliation: roles -> user_role_lookup
    IF to_regclass('public.roles') IS NOT NULL
       AND to_regclass('public.user_role_lookup') IS NULL THEN
        ALTER TABLE public.roles RENAME TO user_role_lookup;
    END IF;
END $$;

-- ----------------------------------------------------------------------------
-- 1. Roles & Users: user_role_lookup & "system_user"
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_role_lookup (
    role_id   INT PRIMARY KEY,
    role_name VARCHAR(64) NOT NULL
);

INSERT INTO user_role_lookup (role_id, role_name) VALUES
    (1, 'Administrator'),
    (2, 'Staff')
ON CONFLICT (role_id) DO UPDATE SET role_name = EXCLUDED.role_name;

CREATE TABLE IF NOT EXISTS "system_user" (
    user_id       SERIAL PRIMARY KEY,
    full_name     VARCHAR(150) NOT NULL DEFAULT '',
    username      VARCHAR(80)  NOT NULL,
    password_hash TEXT         NOT NULL DEFAULT '',
    role_id       INT REFERENCES user_role_lookup(role_id),
    is_deleted    BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ  DEFAULT NOW(),
    updated_at    TIMESTAMPTZ  DEFAULT NOW()
);

ALTER TABLE "system_user" ADD COLUMN IF NOT EXISTS full_name     VARCHAR(150) NOT NULL DEFAULT '';
ALTER TABLE "system_user" ADD COLUMN IF NOT EXISTS username      VARCHAR(80);
ALTER TABLE "system_user" ADD COLUMN IF NOT EXISTS password_hash TEXT         NOT NULL DEFAULT '';
ALTER TABLE "system_user" ADD COLUMN IF NOT EXISTS role_id       INT;
ALTER TABLE "system_user" ADD COLUMN IF NOT EXISTS is_deleted    BOOLEAN      NOT NULL DEFAULT FALSE;
ALTER TABLE "system_user" ADD COLUMN IF NOT EXISTS created_at    TIMESTAMPTZ  DEFAULT NOW();
ALTER TABLE "system_user" ADD COLUMN IF NOT EXISTS updated_at    TIMESTAMPTZ  DEFAULT NOW();

UPDATE "system_user" SET full_name = username WHERE (full_name IS NULL OR full_name = '') AND username IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_system_user_username ON "system_user" (username);

-- ----------------------------------------------------------------------------
-- 2. Seven (7) Lookup Tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS soil_types (
    soil_type_id SERIAL PRIMARY KEY,
    description  VARCHAR(100) NOT NULL,
    is_deleted   BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE soil_types ADD COLUMN IF NOT EXISTS description VARCHAR(100);
ALTER TABLE soil_types ADD COLUMN IF NOT EXISTS is_deleted  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE soil_types ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ DEFAULT NOW();

CREATE TABLE IF NOT EXISTS irrigation_types (
    irrigation_type_id SERIAL PRIMARY KEY,
    description        VARCHAR(100) NOT NULL,
    is_deleted         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE irrigation_types ADD COLUMN IF NOT EXISTS description VARCHAR(100);
ALTER TABLE irrigation_types ADD COLUMN IF NOT EXISTS is_deleted  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE irrigation_types ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ DEFAULT NOW();

CREATE TABLE IF NOT EXISTS crop_categories (
    category_id SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE crop_categories ADD COLUMN IF NOT EXISTS description VARCHAR(100);
ALTER TABLE crop_categories ADD COLUMN IF NOT EXISTS is_deleted  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE crop_categories ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ DEFAULT NOW();

CREATE TABLE IF NOT EXISTS seasons (
    season_id   SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE seasons ADD COLUMN IF NOT EXISTS description VARCHAR(100);
ALTER TABLE seasons ADD COLUMN IF NOT EXISTS is_deleted  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE seasons ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ DEFAULT NOW();

CREATE TABLE IF NOT EXISTS planting_status (
    status_id   SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE planting_status ADD COLUMN IF NOT EXISTS description VARCHAR(100);
ALTER TABLE planting_status ADD COLUMN IF NOT EXISTS is_deleted  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE planting_status ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ DEFAULT NOW();

CREATE TABLE IF NOT EXISTS quality_grades (
    grade_id    SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE quality_grades ADD COLUMN IF NOT EXISTS description VARCHAR(100);
ALTER TABLE quality_grades ADD COLUMN IF NOT EXISTS is_deleted  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE quality_grades ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ DEFAULT NOW();

CREATE TABLE IF NOT EXISTS harvest_remarks (
    remark_id   SERIAL PRIMARY KEY,
    description VARCHAR(100) NOT NULL,
    is_deleted  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE harvest_remarks ADD COLUMN IF NOT EXISTS description VARCHAR(100);
ALTER TABLE harvest_remarks ADD COLUMN IF NOT EXISTS is_deleted  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE harvest_remarks ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ DEFAULT NOW();

UPDATE soil_types       SET description = 'Unknown' WHERE description IS NULL;
UPDATE irrigation_types SET description = 'Unknown' WHERE description IS NULL;
UPDATE crop_categories  SET description = 'Unknown' WHERE description IS NULL;
UPDATE seasons          SET description = 'Unknown' WHERE description IS NULL;
UPDATE planting_status  SET description = 'Unknown' WHERE description IS NULL;
UPDATE quality_grades   SET description = 'Unknown' WHERE description IS NULL;
UPDATE harvest_remarks  SET description = 'Unknown' WHERE description IS NULL;

ALTER TABLE soil_types       ALTER COLUMN description SET NOT NULL;
ALTER TABLE irrigation_types ALTER COLUMN description SET NOT NULL;
ALTER TABLE crop_categories  ALTER COLUMN description SET NOT NULL;
ALTER TABLE seasons          ALTER COLUMN description SET NOT NULL;
ALTER TABLE planting_status  ALTER COLUMN description SET NOT NULL;
ALTER TABLE quality_grades   ALTER COLUMN description SET NOT NULL;
ALTER TABLE harvest_remarks  ALTER COLUMN description SET NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_soil_types_description       ON soil_types (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_irrigation_types_description ON irrigation_types (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_crop_categories_description  ON crop_categories (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_seasons_description          ON seasons (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_planting_status_description  ON planting_status (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_quality_grades_description   ON quality_grades (description);
CREATE UNIQUE INDEX IF NOT EXISTS uq_harvest_remarks_description  ON harvest_remarks (description);

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

-- ----------------------------------------------------------------------------
-- Lookup Seed Data
-- ----------------------------------------------------------------------------
INSERT INTO soil_types (description) VALUES
    ('Loam'), ('Clay'), ('Sandy'), ('Silt'), ('Peat'), ('Chalky')
ON CONFLICT (description) DO NOTHING;

INSERT INTO irrigation_types (description) VALUES
    ('Rainfed'), ('Drip Irrigation'), ('Sprinkler'), ('Canal / Surface'), ('Pump / Well')
ON CONFLICT (description) DO NOTHING;

INSERT INTO crop_categories (description) VALUES
    ('Grains'), ('Vegetables'), ('Fruits'), ('Root Crops'), ('Legumes'), ('Cash Crops')
ON CONFLICT (description) DO NOTHING;

INSERT INTO seasons (description) VALUES
    ('Dry Season'), ('Wet Season'), ('All-Year')
ON CONFLICT (description) DO NOTHING;

-- Safe planting_status seeding that avoids both status_id and description collisions
INSERT INTO planting_status (status_id, description)
SELECT v.status_id, v.description
FROM (VALUES
    (1, 'Planted'),
    (2, 'Growing'),
    (3, 'Harvested')
) AS v(status_id, description)
WHERE NOT EXISTS (
    SELECT 1 FROM planting_status ps
    WHERE ps.status_id = v.status_id OR LOWER(ps.description) = LOWER(v.description)
);

SELECT setval(
    pg_get_serial_sequence('planting_status', 'status_id'),
    GREATEST((SELECT COALESCE(MAX(status_id), 1) FROM planting_status), 3)
);

INSERT INTO quality_grades (description) VALUES
    ('Grade A - Premium'), ('Grade B - Standard'), ('Grade C - Processing')
ON CONFLICT (description) DO NOTHING;

INSERT INTO harvest_remarks (description) VALUES
    ('Good Yield'), ('Normal Harvest'), ('Weather Affected'), ('Pest / Disease Impact')
ON CONFLICT (description) DO NOTHING;

-- ----------------------------------------------------------------------------
-- 3. Five (5) Core Operational Tables
-- ----------------------------------------------------------------------------
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
    created_by        INT REFERENCES "system_user"(user_id),
    updated_by        INT REFERENCES "system_user"(user_id)
);
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS first_name        VARCHAR(100);
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS last_name         VARCHAR(100);
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS contact_no        VARCHAR(30);
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS address           TEXT;
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS registration_date DATE;
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS is_deleted        BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS created_at        TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS updated_at        TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS created_by        INT REFERENCES "system_user"(user_id);
ALTER TABLE farmers ADD COLUMN IF NOT EXISTS updated_by        INT REFERENCES "system_user"(user_id);

UPDATE farmers SET registration_date = COALESCE(created_at::date, CURRENT_DATE)
 WHERE registration_date IS NULL;
ALTER TABLE farmers ALTER COLUMN registration_date SET DEFAULT CURRENT_DATE;

CREATE TABLE IF NOT EXISTS farms (
    farm_id            SERIAL PRIMARY KEY,
    farmer_id          INT NOT NULL REFERENCES farmers(farmer_id),
    farm_name          VARCHAR(150) NOT NULL,
    location           VARCHAR(255),
    area_hectares      NUMERIC(10,2),
    soil_type_id       INT REFERENCES soil_types(soil_type_id),
    irrigation_type_id INT REFERENCES irrigation_types(irrigation_type_id),
    is_deleted         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMPTZ DEFAULT NOW(),
    updated_at         TIMESTAMPTZ DEFAULT NOW(),
    created_by         INT REFERENCES "system_user"(user_id),
    updated_by         INT REFERENCES "system_user"(user_id)
);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS farm_name          VARCHAR(150);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS location           VARCHAR(255);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS area_hectares      NUMERIC(10,2);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS soil_type_id       INT REFERENCES soil_types(soil_type_id);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS irrigation_type_id INT REFERENCES irrigation_types(irrigation_type_id);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS is_deleted         BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE farms ADD COLUMN IF NOT EXISTS created_at         TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farms ADD COLUMN IF NOT EXISTS updated_at         TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE farms ADD COLUMN IF NOT EXISTS created_by         INT REFERENCES "system_user"(user_id);
ALTER TABLE farms ADD COLUMN IF NOT EXISTS updated_by         INT REFERENCES "system_user"(user_id);

CREATE TABLE IF NOT EXISTS crops (
    crop_id      SERIAL PRIMARY KEY,
    crop_name    VARCHAR(150) NOT NULL,
    variety      VARCHAR(100),
    category_id  INT REFERENCES crop_categories(category_id),
    growing_days INT,
    season_id    INT REFERENCES seasons(season_id),
    is_deleted   BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ DEFAULT NOW(),
    updated_at   TIMESTAMPTZ DEFAULT NOW(),
    created_by   INT REFERENCES "system_user"(user_id),
    updated_by   INT REFERENCES "system_user"(user_id)
);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS crop_name    VARCHAR(150);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS variety      VARCHAR(100);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS category_id  INT REFERENCES crop_categories(category_id);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS growing_days INT;
ALTER TABLE crops ADD COLUMN IF NOT EXISTS season_id    INT REFERENCES seasons(season_id);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS is_deleted   BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE crops ADD COLUMN IF NOT EXISTS created_at   TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE crops ADD COLUMN IF NOT EXISTS updated_at   TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE crops ADD COLUMN IF NOT EXISTS created_by   INT REFERENCES "system_user"(user_id);
ALTER TABLE crops ADD COLUMN IF NOT EXISTS updated_by   INT REFERENCES "system_user"(user_id);

CREATE TABLE IF NOT EXISTS plantings (
    planting_id           SERIAL PRIMARY KEY,
    farm_id               INT NOT NULL REFERENCES farms(farm_id),
    crop_id               INT NOT NULL REFERENCES crops(crop_id),
    planting_date         DATE NOT NULL,
    area_planted_hectares NUMERIC(10,2),
    seed_quantity_kg      NUMERIC(12,2),
    expected_harvest_date DATE,
    status_id             INT REFERENCES planting_status(status_id),
    is_deleted            BOOLEAN NOT NULL DEFAULT FALSE,
    created_at            TIMESTAMPTZ DEFAULT NOW(),
    updated_at            TIMESTAMPTZ DEFAULT NOW(),
    created_by            INT REFERENCES "system_user"(user_id),
    updated_by            INT REFERENCES "system_user"(user_id)
);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS area_planted_hectares NUMERIC(10,2);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS seed_quantity_kg      NUMERIC(12,2);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS expected_harvest_date DATE;
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS status_id             INT REFERENCES planting_status(status_id);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS is_deleted            BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS created_at            TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS updated_at            TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS created_by            INT REFERENCES "system_user"(user_id);
ALTER TABLE plantings ADD COLUMN IF NOT EXISTS updated_by            INT REFERENCES "system_user"(user_id);

CREATE TABLE IF NOT EXISTS harvests (
    harvest_id           SERIAL PRIMARY KEY,
    planting_id          INT NOT NULL REFERENCES plantings(planting_id),
    harvest_date         DATE NOT NULL,
    quantity_kg          NUMERIC(12,2),
    selling_price_per_kg NUMERIC(12,2),
    grade_id             INT REFERENCES quality_grades(grade_id),
    remark_id            INT REFERENCES harvest_remarks(remark_id),
    is_deleted           BOOLEAN NOT NULL DEFAULT FALSE,
    created_at           TIMESTAMPTZ DEFAULT NOW(),
    updated_at           TIMESTAMPTZ DEFAULT NOW(),
    created_by           INT REFERENCES "system_user"(user_id),
    updated_by           INT REFERENCES "system_user"(user_id)
);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS quantity_kg          NUMERIC(12,2);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS selling_price_per_kg NUMERIC(12,2);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS grade_id             INT REFERENCES quality_grades(grade_id);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS remark_id            INT REFERENCES harvest_remarks(remark_id);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS is_deleted           BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS created_at           TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS updated_at           TIMESTAMPTZ DEFAULT NOW();
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS created_by           INT REFERENCES "system_user"(user_id);
ALTER TABLE harvests ADD COLUMN IF NOT EXISTS updated_by           INT REFERENCES "system_user"(user_id);

-- ----------------------------------------------------------------------------
-- Legacy column migration & relaxation (Non-destructive)
-- ----------------------------------------------------------------------------
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

    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'farmers'
                  AND column_name = 'barangay_id' AND is_nullable = 'NO') THEN
        ALTER TABLE public.farmers ALTER COLUMN barangay_id DROP NOT NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'farms'
                  AND column_name = 'barangay_id' AND is_nullable = 'NO') THEN
        ALTER TABLE public.farms ALTER COLUMN barangay_id DROP NOT NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'crops'
                  AND column_name = 'selling_price_per_kg' AND is_nullable = 'NO') THEN
        ALTER TABLE public.crops ALTER COLUMN selling_price_per_kg DROP NOT NULL;
    END IF;

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
-- 4. Foreign Key Constraints & Check Constraints
-- ----------------------------------------------------------------------------
DO $$
DECLARE
    r RECORD;
    rel_oid REGCLASS;
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('system_user', 'role_id',            'user_role_lookup', 'role_id'),
            ('farmers',     'created_by',         'system_user',      'user_id'),
            ('farmers',     'updated_by',         'system_user',      'user_id'),
            ('farms',       'farmer_id',          'farmers',          'farmer_id'),
            ('farms',       'soil_type_id',       'soil_types',       'soil_type_id'),
            ('farms',       'irrigation_type_id', 'irrigation_types', 'irrigation_type_id'),
            ('farms',       'created_by',         'system_user',      'user_id'),
            ('farms',       'updated_by',         'system_user',      'user_id'),
            ('crops',       'category_id',        'crop_categories',  'category_id'),
            ('crops',       'season_id',          'seasons',          'season_id'),
            ('crops',       'created_by',         'system_user',      'user_id'),
            ('crops',       'updated_by',         'system_user',      'user_id'),
            ('plantings',   'farm_id',            'farms',            'farm_id'),
            ('plantings',   'crop_id',            'crops',            'crop_id'),
            ('plantings',   'status_id',          'planting_status',  'status_id'),
            ('plantings',   'created_by',         'system_user',      'user_id'),
            ('plantings',   'updated_by',         'system_user',      'user_id'),
            ('harvests',    'planting_id',        'plantings',        'planting_id'),
            ('harvests',    'grade_id',           'quality_grades',   'grade_id'),
            ('harvests',    'remark_id',          'harvest_remarks',  'remark_id'),
            ('harvests',    'created_by',         'system_user',      'user_id'),
            ('harvests',    'updated_by',         'system_user',      'user_id')
        ) AS v(tbl, col, ref_tbl, ref_col)
    LOOP
        rel_oid := format('public.%I', r.tbl)::regclass;
        IF NOT EXISTS (
            SELECT 1
              FROM pg_constraint c
              JOIN pg_attribute  a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
             WHERE c.contype  = 'f'
               AND c.conrelid = rel_oid
               AND a.attname  = r.col
        ) THEN
            EXECUTE format(
                'ALTER TABLE public.%I ADD CONSTRAINT %I FOREIGN KEY (%I) REFERENCES public.%I (%I) NOT VALID',
                r.tbl, 'fk_' || r.tbl || '_' || r.col, r.col, r.ref_tbl, r.ref_col);
        END IF;
    END LOOP;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'chk_plantings_harvest_after_planting'
           AND conrelid = 'public.plantings'::regclass
    ) THEN
        ALTER TABLE public.plantings
            ADD CONSTRAINT chk_plantings_harvest_after_planting
            CHECK (expected_harvest_date > planting_date) NOT VALID;
    END IF;
END $$;

-- ----------------------------------------------------------------------------
-- 5. Audit Trail: activity_log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    log_id       SERIAL PRIMARY KEY,
    user_id      INT REFERENCES "system_user"(user_id) ON DELETE SET NULL,
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
-- 6. Performance & Integrity Indexes
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_system_user_is_deleted      ON "system_user" (is_deleted);
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

CREATE INDEX IF NOT EXISTS idx_system_user_role_id         ON "system_user" (role_id);
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

CREATE INDEX IF NOT EXISTS idx_activity_log_created_at     ON activity_log (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_log_user_id        ON activity_log (user_id);
CREATE INDEX IF NOT EXISTS idx_activity_log_target         ON activity_log (target_table, record_id);

-- ----------------------------------------------------------------------------
-- 7. Supabase Public Data API Hardening (Row Level Security)
-- ----------------------------------------------------------------------------
ALTER TABLE user_role_lookup ENABLE ROW LEVEL SECURITY;
ALTER TABLE "system_user"      ENABLE ROW LEVEL SECURITY;
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