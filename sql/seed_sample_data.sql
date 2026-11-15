-- ============================================================================
--  Sproutly  |  sql/seed_sample_data.sql
--  Safe sample-data seed: 15 farmers, 15 farms, 10 crops, 50 plantings, 20 harvests.
--
--  SAFETY RULES:
--    • No TRUNCATE, DROP, or DELETE statements anywhere.
--    • system_user, user_role_lookup, and activity_log rows are never touched.
--    • Fully idempotent — safe to run multiple times without duplicate errors:
--        – Crops:   ON CONFLICT (crop_name) DO NOTHING
--        – Farmers: WHERE NOT EXISTS … matched on contact_no
--        – Farms:   WHERE NOT EXISTS … matched on farm_name
--        – Plantings: WHERE NOT EXISTS … matched on (farm_id, crop_id, planting_date)
--        – Harvests:  WHERE NOT EXISTS … matched on planting_id
--    • Runs inside a single transaction — any error rolls everything back.
--
--  DESIGN — two phases so IDs are always resolved from the live table,
--  not solely from RETURNING (which returns nothing when ON CONFLICT skips):
--
--    Phase 1  Plain INSERTs (outside CTEs):
--               Step 1 – lookup values
--               Step 2 – 10 crops      (ON CONFLICT (crop_name) DO NOTHING)
--               Step 3 – 15 farmers    (WHERE NOT EXISTS on contact_no)
--               Step 4 – 15 farms      (WHERE NOT EXISTS on farm_name)
--
--    Phase 2  Single CTE block:
--               Step 5 – resolve farm_id, crop_id, growing_days by name
--               Step 6 – insert 50 plantings (WHERE NOT EXISTS guard)
--               Step 7 – resolve planting_id from live table by (farm_id, crop_id, planting_date)
--               Step 8 – insert 20 harvests   (WHERE NOT EXISTS guard)
--
--  HOW TO RUN:
--    Paste the entire file into Supabase SQL Editor and click "Run".
-- ============================================================================

BEGIN;

-- ============================================================================
-- STEP 1 – Ensure lookup values exist (idempotent; never overwrites)
-- ============================================================================

INSERT INTO soil_types (description) VALUES
    ('Loam'), ('Clay'), ('Sandy Loam'), ('Clay Loam')
ON CONFLICT (description) DO NOTHING;

INSERT INTO irrigation_types (description) VALUES
    ('Rainfed'), ('Irrigated'), ('Drip Irrigation')
ON CONFLICT (description) DO NOTHING;

INSERT INTO crop_categories (description) VALUES
    ('Grains'), ('Vegetables'), ('Fruits'), ('Legumes'), ('Root Crops')
ON CONFLICT (description) DO NOTHING;

-- 'Year Round' used by install.php; schema seeds 'All-Year' — add both safely.
INSERT INTO seasons (description) VALUES
    ('Wet Season'), ('Dry Season'), ('Year Round'), ('All-Year')
ON CONFLICT (description) DO NOTHING;

-- planting_status – fixed IDs (1=Planted, 2=Growing, 3=Harvested)
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

INSERT INTO quality_grades (description) VALUES
    ('Grade A - Premium'), ('Grade B - Standard'), ('Grade C - Processing')
ON CONFLICT (description) DO NOTHING;

INSERT INTO harvest_remarks (description) VALUES
    ('Good Yield'), ('Normal Harvest'), ('Weather Affected'), ('Pest / Disease Impact')
ON CONFLICT (description) DO NOTHING;

-- ============================================================================
-- STEP 2 – Insert 10 crops
--   ON CONFLICT (crop_name) DO NOTHING handles the case where crops like
--   'Rice' already exist (error 23505 crops_crop_name_key).
--   crop_id is resolved later in Phase 2 by name, not from RETURNING.
-- ============================================================================

INSERT INTO crops (crop_name, variety, category_id, growing_days, season_id, is_deleted)
SELECT
    c.crop_name,
    c.variety,
    (SELECT category_id FROM crop_categories WHERE description = c.cat_desc  LIMIT 1),
    c.growing_days,
    (SELECT season_id   FROM seasons           WHERE description = c.ssn_desc LIMIT 1),
    FALSE
FROM (VALUES
    ('Rice',         'NSIC Rc 222',   'Grains',     115, 'Wet Season'),
    ('Corn',         'Yellow Corn',   'Grains',     100, 'Dry Season'),
    ('Tomato',       'Diamante Max',  'Vegetables',  90, 'Dry Season'),
    ('Eggplant',     'Morena',        'Vegetables', 100, 'Year Round'),
    ('Banana',       'Lakatan',       'Fruits',     300, 'Year Round'),
    ('Mango',        'Carabao',       'Fruits',     150, 'Dry Season'),
    ('Peanut',       'BPI Pn 9',      'Legumes',    110, 'Dry Season'),
    ('Cassava',      'Golden Yellow', 'Root Crops', 240, 'Year Round'),
    ('Sweet Potato', 'NSIC Sp 30',    'Root Crops', 120, 'Year Round'),
    ('Watermelon',   'Sweet 16',      'Fruits',      85, 'Dry Season')
) AS c(crop_name, variety, cat_desc, growing_days, ssn_desc)
ON CONFLICT (crop_name) DO NOTHING;

-- ============================================================================
-- STEP 3 – Insert 15 farmers
--   Guard: WHERE NOT EXISTS matched on contact_no (unique in our dataset).
--   If a farmer with that contact number already exists the row is skipped.
-- ============================================================================

INSERT INTO farmers (first_name, last_name, contact_no, address, registration_date, is_deleted)
SELECT v.first_name, v.last_name, v.contact_no, v.address, '2026-01-01'::date, FALSE
FROM (VALUES
    ('Juan',    'Dela Cruz',  '09171234567', 'Cebu City'),
    ('Maria',   'Santos',     '09181234567', 'Carcar City, Cebu'),
    ('Pedro',   'Reyes',      '09191234567', 'Danao City, Cebu'),
    ('Ana',     'Garcia',     '09201234567', 'Argao, Cebu'),
    ('Jose',    'Mendoza',    '09211234567', 'Bogo City, Cebu'),
    ('Ramon',   'Flores',     '09221234567', 'Toledo City, Cebu'),
    ('Elena',   'Cruz',       '09231234567', 'Naga City, Cebu'),
    ('Antonio', 'Lim',        '09241234567', 'Minglanilla, Cebu'),
    ('Rosa',    'Fernandez',  '09251234567', 'Liloan, Cebu'),
    ('Mario',   'Villanueva', '09261234567', 'Compostela, Cebu'),
    ('Teresa',  'Navarro',    '09271234567', 'Talisay City, Cebu'),
    ('Carlos',  'Ramos',      '09281234567', 'Consolacion, Cebu'),
    ('Liza',    'Torres',     '09291234567', 'Dalaguete, Cebu'),
    ('Roberto', 'Castillo',   '09301234567', 'Barili, Cebu'),
    ('Susan',   'Aquino',     '09311234567', 'Sibonga, Cebu')
) AS v(first_name, last_name, contact_no, address)
WHERE NOT EXISTS (
    SELECT 1 FROM farmers f WHERE f.contact_no = v.contact_no
);

-- ============================================================================
-- STEP 4 – Insert 15 farms
--   Guard: WHERE NOT EXISTS matched on farm_name (unique in our dataset).
--   farmer_id is resolved at insert time by contact_no lookup.
-- ============================================================================

INSERT INTO farms (
    farmer_id, farm_name, location, area_hectares,
    soil_type_id, irrigation_type_id, is_deleted
)
SELECT
    (SELECT farmer_id FROM farmers WHERE contact_no = v.farmer_contact LIMIT 1),
    v.farm_name,
    v.location,
    v.area_hectares,
    (SELECT soil_type_id       FROM soil_types       WHERE description = v.soil_desc LIMIT 1),
    (SELECT irrigation_type_id FROM irrigation_types WHERE description = v.irr_desc  LIMIT 1),
    FALSE
FROM (VALUES
    ('09171234567', 'Dela Cruz Farm',             'Cebu City',          2.50, 'Loam',      'Rainfed'),
    ('09181234567', 'Santos Rice Farm',           'Carcar City, Cebu',  4.20, 'Clay Loam', 'Irrigated'),
    ('09191234567', 'Reyes Agricultural Farm',    'Danao City, Cebu',   3.80, 'Sandy Loam','Rainfed'),
    ('09201234567', 'Garcia Vegetable Farm',      'Argao, Cebu',        1.75, 'Loam',      'Drip Irrigation'),
    ('09211234567', 'Mendoza Farm',               'Bogo City, Cebu',    5.00, 'Clay',      'Irrigated'),
    ('09221234567', 'Flores Corn Farm',           'Toledo City, Cebu',  3.40, 'Sandy Loam','Rainfed'),
    ('09231234567', 'Cruz Family Farm',           'Naga City, Cebu',    2.80, 'Loam',      'Irrigated'),
    ('09241234567', 'Lim Agricultural Farm',      'Minglanilla, Cebu',  2.25, 'Clay Loam', 'Drip Irrigation'),
    ('09251234567', 'Fernandez Farm',             'Liloan, Cebu',       3.10, 'Sandy Loam','Rainfed'),
    ('09261234567', 'Villanueva Green Farm',      'Compostela, Cebu',   4.50, 'Loam',      'Irrigated'),
    ('09271234567', 'Navarro Rice Field',         'Talisay City, Cebu', 3.75, 'Clay',      'Irrigated'),
    ('09281234567', 'Ramos Farm',                 'Consolacion, Cebu',  2.60, 'Loam',      'Rainfed'),
    ('09291234567', 'Torres Vegetable Farm',      'Dalaguete, Cebu',    1.80, 'Sandy Loam','Drip Irrigation'),
    ('09301234567', 'Castillo Agricultural Farm', 'Barili, Cebu',       5.50, 'Clay Loam', 'Irrigated'),
    ('09311234567', 'Aquino Farm',                'Sibonga, Cebu',      3.20, 'Loam',      'Rainfed')
) AS v(farmer_contact, farm_name, location, area_hectares, soil_desc, irr_desc)
WHERE NOT EXISTS (
    SELECT 1 FROM farms fm WHERE fm.farm_name = v.farm_name
);

-- ============================================================================
-- PHASE 2 – Insert 50 plantings + 20 harvests via a single CTE block.
--
--   All IDs are resolved from the live table by name, so this works whether
--   the rows above were just inserted or already existed.
-- ============================================================================

WITH

-- --------------------------------------------------------------------------
-- Resolve the 15 seed farm IDs from the live table, in insertion order.
-- farm_ord matches the position in the VALUES list above (1 = Dela Cruz Farm).
-- --------------------------------------------------------------------------
seed_farms(farm_ord, farm_id) AS (
    SELECT v.farm_ord, fm.farm_id
    FROM (VALUES
        (1,  'Dela Cruz Farm'),
        (2,  'Santos Rice Farm'),
        (3,  'Reyes Agricultural Farm'),
        (4,  'Garcia Vegetable Farm'),
        (5,  'Mendoza Farm'),
        (6,  'Flores Corn Farm'),
        (7,  'Cruz Family Farm'),
        (8,  'Lim Agricultural Farm'),
        (9,  'Fernandez Farm'),
        (10, 'Villanueva Green Farm'),
        (11, 'Navarro Rice Field'),
        (12, 'Ramos Farm'),
        (13, 'Torres Vegetable Farm'),
        (14, 'Castillo Agricultural Farm'),
        (15, 'Aquino Farm')
    ) AS v(farm_ord, farm_name)
    JOIN farms fm ON fm.farm_name = v.farm_name AND fm.is_deleted = FALSE
),

-- --------------------------------------------------------------------------
-- Resolve the 10 seed crop IDs + growing_days from the live table.
-- crop_ord matches install.php array order (1 = Rice … 10 = Watermelon).
-- --------------------------------------------------------------------------
seed_crops(crop_ord, crop_id, growing_days) AS (
    SELECT v.crop_ord, c.crop_id, v.growing_days
    FROM (VALUES
        (1,  'Rice',         115),
        (2,  'Corn',         100),
        (3,  'Tomato',        90),
        (4,  'Eggplant',     100),
        (5,  'Banana',       300),
        (6,  'Mango',        150),
        (7,  'Peanut',       110),
        (8,  'Cassava',      240),
        (9,  'Sweet Potato', 120),
        (10, 'Watermelon',    85)
    ) AS v(crop_ord, crop_name, growing_days)
    JOIN crops c ON c.crop_name = v.crop_name AND c.is_deleted = FALSE
),

-- --------------------------------------------------------------------------
-- Build the full 50-row planting matrix (mirrors install.php logic exactly):
--   i = 0..49
--   farm_ord          = (i % 15) + 1
--   crop_ord          = (i % 10) + 1
--   planting_date     = 2026-01-10 + i*3 days
--   expected_harvest  = planting_date + growing_days days
--   status_id         = 3 (Harvested) for i<20, else 2 (Growing)
--   area_planted_ha   = 0.50 + (i%10)*0.10
--   seed_quantity_kg  = 10 + (i%8)*5
-- --------------------------------------------------------------------------
planting_matrix AS (
    SELECT
        i,
        (i % 15) + 1                                              AS farm_ord,
        (i % 10) + 1                                              AS crop_ord,
        ('2026-01-10'::date + (i * 3) * INTERVAL '1 day')::date  AS planting_date,
        ('2026-01-10'::date
            + (i * 3)             * INTERVAL '1 day'
            + sc.growing_days     * INTERVAL '1 day')::date       AS expected_harvest_date,
        CASE WHEN i < 20 THEN 3 ELSE 2 END                        AS status_id,
        ROUND((0.50 + (i % 10) * 0.10)::numeric, 2)               AS area_planted_hectares,
        (10 + (i % 8) * 5)::numeric                               AS seed_quantity_kg
    FROM generate_series(0, 49) AS i
    JOIN seed_crops sc ON sc.crop_ord = (i % 10) + 1
),

-- --------------------------------------------------------------------------
-- Insert 50 plantings.
-- Guard: skip any (farm_id, crop_id, planting_date) that already exists,
-- so a re-run adds nothing but also does not error.
-- --------------------------------------------------------------------------
ins_plantings AS (
    INSERT INTO plantings (
        farm_id, crop_id, planting_date,
        area_planted_hectares, seed_quantity_kg,
        expected_harvest_date, status_id, is_deleted
    )
    SELECT
        sf.farm_id,
        sc.crop_id,
        pm.planting_date,
        pm.area_planted_hectares,
        pm.seed_quantity_kg,
        pm.expected_harvest_date,
        pm.status_id,
        FALSE
    FROM planting_matrix pm
    JOIN seed_farms  sf ON sf.farm_ord = pm.farm_ord
    JOIN seed_crops  sc ON sc.crop_ord = pm.crop_ord
    WHERE NOT EXISTS (
        SELECT 1 FROM plantings p
        WHERE p.farm_id       = sf.farm_id
          AND p.crop_id       = sc.crop_id
          AND p.planting_date = pm.planting_date
    )
    ORDER BY pm.i
    RETURNING planting_id, farm_id, crop_id, planting_date
),

-- --------------------------------------------------------------------------
-- Resolve all 50 planting IDs from the live table.
-- Covers BOTH just-inserted rows AND pre-existing rows (if re-run).
-- The JOIN to planting_matrix produces exactly the 50 rows in ordinal order.
-- --------------------------------------------------------------------------
all_planting_ids AS (
    SELECT
        pm.i                              AS planting_ord,   -- 0-based, matches harvest i
        p.planting_id,
        pm.crop_ord,
        pm.planting_date
    FROM planting_matrix pm
    JOIN seed_farms sf ON sf.farm_ord = pm.farm_ord
    JOIN seed_crops sc ON sc.crop_ord = pm.crop_ord
    JOIN plantings  p  ON p.farm_id       = sf.farm_id
                       AND p.crop_id       = sc.crop_id
                       AND p.planting_date = pm.planting_date
                       AND p.is_deleted    = FALSE
),

-- --------------------------------------------------------------------------
-- Build the 20-harvest matrix (mirrors install.php logic exactly):
--   i = 0..19
--   harvest_date = planting_date(i) + growing_days + 2 days
--   quantity_kg  = 900 + i*125
--   price        = 22 + (i%8)*3.50
--   grade/remark cycle on i%3
-- --------------------------------------------------------------------------
harvest_matrix AS (
    SELECT
        api.planting_ord                                       AS i,
        api.planting_id,
        (api.planting_date
            + sc.growing_days * INTERVAL '1 day'
            + 2               * INTERVAL '1 day')::date        AS harvest_date,
        (900 + api.planting_ord * 125)::numeric                AS quantity_kg,
        ROUND((22 + (api.planting_ord % 8) * 3.50)::numeric, 2) AS selling_price_per_kg,
        CASE api.planting_ord % 3
            WHEN 0 THEN 'Grade A - Premium'
            WHEN 1 THEN 'Grade B - Standard'
            ELSE        'Grade C - Processing'
        END                                                    AS grade_desc,
        CASE api.planting_ord % 3
            WHEN 0 THEN 'Good Yield'
            WHEN 1 THEN 'Normal Harvest'
            ELSE        'Weather Affected'
        END                                                    AS remark_desc
    FROM all_planting_ids api
    JOIN seed_crops sc ON sc.crop_ord = api.crop_ord
    WHERE api.planting_ord BETWEEN 0 AND 19   -- only the first 20 plantings get harvests
)

-- --------------------------------------------------------------------------
-- Insert 20 harvests.
-- Guard: WHERE NOT EXISTS on planting_id avoids duplicates on re-run.
-- --------------------------------------------------------------------------
INSERT INTO harvests (
    planting_id, harvest_date,
    quantity_kg, selling_price_per_kg,
    grade_id, remark_id, is_deleted
)
SELECT
    hm.planting_id,
    hm.harvest_date,
    hm.quantity_kg,
    hm.selling_price_per_kg,
    (SELECT grade_id  FROM quality_grades  WHERE description = hm.grade_desc  LIMIT 1),
    (SELECT remark_id FROM harvest_remarks WHERE description = hm.remark_desc LIMIT 1),
    FALSE
FROM harvest_matrix hm
WHERE NOT EXISTS (
    SELECT 1 FROM harvests h WHERE h.planting_id = hm.planting_id
)
ORDER BY hm.i;

COMMIT;

-- ============================================================================
-- OPTIONAL VERIFICATION QUERIES (run separately after seeding)
-- ============================================================================
/*
SELECT tbl, cnt FROM (VALUES
    ('farmers',  (SELECT COUNT(*) FROM farmers  WHERE is_deleted = FALSE)),
    ('farms',    (SELECT COUNT(*) FROM farms    WHERE is_deleted = FALSE)),
    ('crops',    (SELECT COUNT(*) FROM crops    WHERE is_deleted = FALSE)),
    ('plantings',(SELECT COUNT(*) FROM plantings WHERE is_deleted = FALSE)),
    ('harvests', (SELECT COUNT(*) FROM harvests  WHERE is_deleted = FALSE))
) AS t(tbl, cnt)
ORDER BY tbl;
*/
