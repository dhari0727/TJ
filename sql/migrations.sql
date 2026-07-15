-- =====================================================================
-- JourneyAI — Database Migration (P0)
-- =====================================================================
-- Purpose:
--   1. Repair the InnoDB-orphaned `project` tables (they existed on disk
--      but were unreadable: "Table doesn't exist in engine"). We drop and
--      recreate them cleanly.
--   2. Recreate the 5 legacy tables with the EXACT positional column order
--      the existing PHP wizard expects, so dashboard*.php keep working
--      byte-for-byte (their INSERTs use positional `VALUES(...)`).
--   3. Add JourneyAI structures: entry_id PK, interactions, destinations,
--      journal_features, and the `journals` read-model VIEW.
--
-- Authoritative column orders (from code — the live tables were unreadable):
--   db      dashboard.php:31   (named INSERT)
--   db1     dashboard1.php:31  (positional; cols 13,14 = FOOD_COST,TRANSPORT_COST — read by name in dashboard3.php:7)
--   db2     dashboard2.php:25  (positional, 11 cols)
--   db3     dashboard3.php:36  (positional, 17 cols)
--   signup  register.php:29    (named INSERT)
--
-- Run:  mysql -u root project < sql/migrations.sql
-- SAFE TO RE-RUN: uses DROP ... IF EXISTS then CREATE.
-- WARNING: drops the current (corrupted, near-empty) legacy tables. The
--          synthetic corpus (ml/seed) repopulates them.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Drop dependent view first, then tables.
DROP VIEW  IF EXISTS journals;
DROP TABLE IF EXISTS journal_features;
DROP TABLE IF EXISTS interactions;
DROP TABLE IF EXISTS destinations;
DROP TABLE IF EXISTS db3;
DROP TABLE IF EXISTS db2;
DROP TABLE IF EXISTS db1;
DROP TABLE IF EXISTS db;
DROP TABLE IF EXISTS signup;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- signup — users. psw widened to hold bcrypt/argon hashes (was plaintext).
-- register.php inserts (fname, lname, eml, psw) by name → order flexible,
-- but we keep it natural.
-- ---------------------------------------------------------------------
CREATE TABLE signup (
    fname  VARCHAR(100)  NOT NULL,
    lname  VARCHAR(100)  NOT NULL,
    eml    VARCHAR(190)  NOT NULL,
    psw    VARCHAR(255)  NOT NULL,          -- was plaintext; now holds password_hash()
    PRIMARY KEY (eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- db — journal entry CORE (wizard step 1).
-- dashboard.php uses a NAMED insert of the first 12 columns, so entry_id
-- is appended LAST and left AUTO_INCREMENT — the named INSERT is unaffected.
-- Positional order of the first 12 columns is preserved exactly.
-- ---------------------------------------------------------------------
CREATE TABLE db (
    Title        VARCHAR(255) NOT NULL,     -- 1
    Description  TEXT,                       -- 2
    Country      VARCHAR(120),               -- 3
    City         VARCHAR(120),               -- 4
    cd           VARCHAR(40),                -- 5  created date (kept VARCHAR — wizard sends date strings)
    dv           VARCHAR(40),                -- 6  date of visit (start)
    dr           VARCHAR(40),                -- 7  date of return (end)
    hn           VARCHAR(255),               -- 8  hotel name
    address      TEXT,                       -- 9
    ptv          TEXT,                       -- 10 places to visit
    tv           VARCHAR(120),               -- 11 travel mode
    eml          VARCHAR(190) NOT NULL,      -- 12 owner email
    entry_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,  -- 13 surrogate PK (JourneyAI)
    PRIMARY KEY (entry_id),
    UNIQUE KEY uq_entry (Title, eml),        -- an entry is keyed by Title+eml across all tables
    KEY idx_db_eml (eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- db1 — expenses: food + transport (wizard step 2).
-- POSITIONAL INSERT (dashboard1.php:31) — 16 columns in EXACT order.
-- Columns 13,14 MUST be named FOOD_COST, TRANSPORT_COST (read by name).
-- ---------------------------------------------------------------------
CREATE TABLE db1 (
    breakfast       VARCHAR(40),   -- 1
    lunch           VARCHAR(40),   -- 2
    snacks          VARCHAR(40),   -- 3
    dinner          VARCHAR(40),   -- 4
    beverages       VARCHAR(40),   -- 5
    car             VARCHAR(40),   -- 6
    taxi            VARCHAR(40),   -- 7
    train           VARCHAR(40),   -- 8
    flight          VARCHAR(40),   -- 9
    underground     VARCHAR(40),   -- 10
    pass            VARCHAR(40),   -- 11
    others          VARCHAR(40),   -- 12
    FOOD_COST       VARCHAR(40),   -- 13 food subtotal (computed in PHP)
    TRANSPORT_COST  VARCHAR(40),   -- 14 transport total (computed in PHP)
    Title           VARCHAR(255),  -- 15 join key
    eml             VARCHAR(190),  -- 16 owner
    KEY idx_db1_join (Title, eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- db2 — accommodation & misc costs (wizard step 3).
-- POSITIONAL INSERT (dashboard2.php:25) — 11 columns in EXACT order.
-- (`int` is a reserved word → must be backtick-quoted.)
-- ---------------------------------------------------------------------
CREATE TABLE db2 (
    ac      VARCHAR(40),   -- 1  accommodation / hotel per night
    loc     VARCHAR(40),   -- 2  luggage locker
    air     VARCHAR(40),   -- 3  airport misc
    tr      VARCHAR(40),   -- 4  tour guide
    ph      VARCHAR(40),   -- 5  phone recharge
    `int`   VARCHAR(40),   -- 6  internet
    cam     VARCHAR(40),   -- 7  camera
    ins     VARCHAR(40),   -- 8  medical insurance
    med     VARCHAR(40),   -- 9  emergency medical
    Title   VARCHAR(255),  -- 10 join key
    eml     VARCHAR(190),  -- 11 owner
    KEY idx_db2_join (Title, eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- db3 — entry fees + shopping + grand total (wizard step 4).
-- POSITIONAL INSERT (dashboard3.php:36) — 17 columns in EXACT order.
-- NOTE: `grand` (col 16) is the LEGACY grand total that OMITS db2 costs.
--       JourneyAI never trusts `grand`; it computes true_total in the view.
-- ---------------------------------------------------------------------
CREATE TABLE db3 (
    mue       VARCHAR(40),   -- 1  museum entry
    pr        VARCHAR(40),   -- 2  park entry
    ci        VARCHAR(40),   -- 3  cinema
    cl        VARCHAR(40),   -- 4  club
    pt        VARCHAR(40),   -- 5  party
    clt       VARCHAR(40),   -- 6  clothes
    carr      VARCHAR(40),   -- 7  cards
    gi        VARCHAR(40),   -- 8  gifts
    fl        VARCHAR(40),   -- 9  flight
    so        VARCHAR(40),   -- 10 souvenirs
    stamps    VARCHAR(40),   -- 11 stamps
    misc      VARCHAR(40),   -- 12 misc (stored, excluded from legacy subtotals)
    subTotal  VARCHAR(40),   -- 13 entry-fees subtotal (computed in PHP)
    total     VARCHAR(40),   -- 14 shopping subtotal (computed in PHP)
    Title     VARCHAR(255),  -- 15 join key
    grand     VARCHAR(40),   -- 16 LEGACY grand total (omits db2 — do not trust)
    eml       VARCHAR(190),  -- 17 owner
    KEY idx_db3_join (Title, eml)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- JourneyAI NEW structures
-- =====================================================================

-- ---------------------------------------------------------------------
-- destinations — canonical reference (Country/City are free text in db).
-- Drives the "surface lesser-known destinations" discovery boost.
-- ---------------------------------------------------------------------
CREATE TABLE destinations (
    dest_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    canonical_name  VARCHAR(160) NOT NULL,   -- "City, Country"
    country         VARCHAR(120),
    city            VARCHAR(120),
    region          VARCHAR(80),
    popularity_tier ENUM('mainstream','lesser_known') NOT NULL DEFAULT 'mainstream',
    base_daily_cost DECIMAL(10,2),           -- seed prior; helps cost sanity
    lat             DECIMAL(9,6) NULL,
    lon             DECIMAL(9,6) NULL,
    PRIMARY KEY (dest_id),
    UNIQUE KEY uq_dest (canonical_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- interactions — user↔destination signals for collaborative filtering
-- and for capturing real actions (view/save/plan a recommendation).
-- ---------------------------------------------------------------------
CREATE TABLE interactions (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    eml               VARCHAR(190) NOT NULL,
    destination       VARCHAR(160) NOT NULL,    -- canonical_name
    entry_id          BIGINT UNSIGNED NULL,     -- optional link to a db entry
    rating            TINYINT NULL,             -- 1..5 (NULL for implicit)
    interaction_type  ENUM('rating','view','save','plan') NOT NULL DEFAULT 'rating',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_int_eml (eml),
    KEY idx_int_dest (destination),
    KEY idx_int_type (interaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- journal_features — NLP-derived feature cache (one row per db entry).
-- Written by ml/nlp/extract_features.py; read by the recommender.
-- ---------------------------------------------------------------------
CREATE TABLE journal_features (
    entry_id        BIGINT UNSIGNED NOT NULL,
    eml             VARCHAR(190),
    canonical_dest  VARCHAR(160),
    budget_bucket   ENUM('shoestring','budget','mid','premium','luxury') NULL,
    duration_days   INT NULL,
    activities      JSON NULL,                -- extracted interests/activities
    travel_style    VARCHAR(40) NULL,
    sentiment_score DECIMAL(5,3) NULL,        -- -1..1
    sentiment_label ENUM('negative','neutral','positive') NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (entry_id),
    KEY idx_jf_dest (canonical_dest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- journals — normalized READ MODEL joining db + db1 + db2 + db3 on
-- (Title, eml). Exposes duration_days and true_total (sum of ALL raw
-- cost columns — NOT db3.grand, which omits db2). All cost columns are
-- VARCHAR in the base tables, so CAST to DECIMAL and COALESCE blanks→0.
-- ---------------------------------------------------------------------
CREATE VIEW journals AS
SELECT
    d.entry_id,
    d.eml,
    d.Title,
    d.Description,
    d.Country,
    d.City,
    d.cd,
    d.dv,
    d.dr,
    d.hn,
    d.address,
    d.ptv,
    d.tv,
    DATEDIFF(d.dr, d.dv)                                       AS duration_days,

    -- per-category totals (blanks/NULLs → 0)
    ( COALESCE(NULLIF(e1.breakfast,''),0) + COALESCE(NULLIF(e1.lunch,''),0)
    + COALESCE(NULLIF(e1.snacks,''),0)    + COALESCE(NULLIF(e1.dinner,''),0)
    + COALESCE(NULLIF(e1.beverages,''),0) )                    AS food_total,

    ( COALESCE(NULLIF(e1.car,''),0)   + COALESCE(NULLIF(e1.taxi,''),0)
    + COALESCE(NULLIF(e1.train,''),0) + COALESCE(NULLIF(e1.flight,''),0)
    + COALESCE(NULLIF(e1.underground,''),0) + COALESCE(NULLIF(e1.pass,''),0)
    + COALESCE(NULLIF(e1.others,''),0) )                       AS transport_total,

    ( COALESCE(NULLIF(e2.ac,''),0)  + COALESCE(NULLIF(e2.loc,''),0)
    + COALESCE(NULLIF(e2.air,''),0) + COALESCE(NULLIF(e2.tr,''),0)
    + COALESCE(NULLIF(e2.ph,''),0)  + COALESCE(NULLIF(e2.`int`,''),0)
    + COALESCE(NULLIF(e2.cam,''),0) + COALESCE(NULLIF(e2.ins,''),0)
    + COALESCE(NULLIF(e2.med,''),0) )                          AS accommodation_total,

    ( COALESCE(NULLIF(e3.clt,''),0) + COALESCE(NULLIF(e3.carr,''),0)
    + COALESCE(NULLIF(e3.gi,''),0)  + COALESCE(NULLIF(e3.fl,''),0)
    + COALESCE(NULLIF(e3.so,''),0)  + COALESCE(NULLIF(e3.stamps,''),0) ) AS shopping_total,

    ( COALESCE(NULLIF(e3.mue,''),0) + COALESCE(NULLIF(e3.pr,''),0)
    + COALESCE(NULLIF(e3.ci,''),0)  + COALESCE(NULLIF(e3.cl,''),0)
    + COALESCE(NULLIF(e3.pt,''),0)  + COALESCE(NULLIF(e3.misc,''),0) )   AS fees_misc_total,

    -- true_total = sum of ALL raw cost columns across db1+db2+db3
    (
        COALESCE(NULLIF(e1.breakfast,''),0) + COALESCE(NULLIF(e1.lunch,''),0)
      + COALESCE(NULLIF(e1.snacks,''),0)    + COALESCE(NULLIF(e1.dinner,''),0)
      + COALESCE(NULLIF(e1.beverages,''),0) + COALESCE(NULLIF(e1.car,''),0)
      + COALESCE(NULLIF(e1.taxi,''),0)      + COALESCE(NULLIF(e1.train,''),0)
      + COALESCE(NULLIF(e1.flight,''),0)    + COALESCE(NULLIF(e1.underground,''),0)
      + COALESCE(NULLIF(e1.pass,''),0)      + COALESCE(NULLIF(e1.others,''),0)
      + COALESCE(NULLIF(e2.ac,''),0)  + COALESCE(NULLIF(e2.loc,''),0)
      + COALESCE(NULLIF(e2.air,''),0) + COALESCE(NULLIF(e2.tr,''),0)
      + COALESCE(NULLIF(e2.ph,''),0)  + COALESCE(NULLIF(e2.`int`,''),0)
      + COALESCE(NULLIF(e2.cam,''),0) + COALESCE(NULLIF(e2.ins,''),0)
      + COALESCE(NULLIF(e2.med,''),0)
      + COALESCE(NULLIF(e3.mue,''),0) + COALESCE(NULLIF(e3.pr,''),0)
      + COALESCE(NULLIF(e3.ci,''),0)  + COALESCE(NULLIF(e3.cl,''),0)
      + COALESCE(NULLIF(e3.pt,''),0)  + COALESCE(NULLIF(e3.clt,''),0)
      + COALESCE(NULLIF(e3.carr,''),0)+ COALESCE(NULLIF(e3.gi,''),0)
      + COALESCE(NULLIF(e3.fl,''),0)  + COALESCE(NULLIF(e3.so,''),0)
      + COALESCE(NULLIF(e3.stamps,''),0) + COALESCE(NULLIF(e3.misc,''),0)
    )                                                          AS true_total,

    -- legacy grand total kept for comparison/demo (proves the bug)
    CAST(COALESCE(NULLIF(e3.grand,''),0) AS DECIMAL(12,2))     AS legacy_grand
FROM db d
LEFT JOIN db1 e1 ON e1.Title = d.Title AND e1.eml = d.eml
LEFT JOIN db2 e2 ON e2.Title = d.Title AND e2.eml = d.eml
LEFT JOIN db3 e3 ON e3.Title = d.Title AND e3.eml = d.eml;
