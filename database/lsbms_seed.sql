-- =====================================================================
--  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
--  BCSP-064 -- Bachelor of Computer Applications, IGNOU
-- ---------------------------------------------------------------------
--  FILE    : lsbms_seed.sql
--  PURPOSE : Demonstration dataset. Run AFTER lsbms_schema.sql.
--
--  Every screen in the application has representative content here, so
--  the input/output screenshots required by the project report show a
--  realistic system rather than empty tables.
--
--  DEMO PASSWORD for every seeded account : Lsbms@2026
--  The stored value is a bcrypt hash produced by PHP password_hash().
--  No plaintext password appears anywhere in this file or the database.
-- =====================================================================

USE lsbms_db;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE activity_log;
TRUNCATE TABLE notifications;
TRUNCATE TABLE payments;
TRUNCATE TABLE feedback;
TRUNCATE TABLE maintenance_visits;
TRUNCATE TABLE maintenance_contracts;
TRUNCATE TABLE maintenance_plans;
TRUNCATE TABLE booking_status_history;
TRUNCATE TABLE bookings;
TRUNCATE TABLE services;
TRUNCATE TABLE provider_availability;
TRUNCATE TABLE providers;
TRUNCATE TABLE categories;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------------------
-- 1. SERVICE CATEGORIES
-- ---------------------------------------------------------------------
INSERT INTO categories (category_id, category_name, description, icon, is_active) VALUES
(1, 'Plumbing',              'Taps, pipelines, leakages, bathroom fittings and water tanks.', '🔧', 1),
(2, 'Electrical',            'Wiring, switchboards, inverters, fans and lighting.',           '⚡', 1),
(3, 'AC & Appliance Repair', 'Air conditioners, refrigerators, washing machines, geysers.',   '❄️', 1),
(4, 'Carpentry',             'Furniture repair, modular fittings, doors and wardrobes.',      '🔨', 1),
(5, 'Home Cleaning',         'Deep cleaning, sofa and kitchen cleaning, sanitisation.',       '🧹', 1),
(6, 'Painting',              'Interior and exterior painting, waterproofing, texture work.',  '🎨', 1),
(7, 'Pest Control',          'Cockroach, termite, mosquito and rodent treatment.',            '🐜', 1),
(8, 'Computer & IT Support', 'Desktop and laptop repair, networking, CCTV installation.',     '💻', 1);


-- ---------------------------------------------------------------------
-- 2. USERS
--    user_id 1        -> administrator
--    user_id 2  - 7   -> service providers
--    user_id 8  - 12  -> customers
--    All hashes are bcrypt of 'Lsbms@2026'.
-- ---------------------------------------------------------------------
INSERT INTO users (user_id, full_name, email, password_hash, phone, address, city, pincode, role, status) VALUES
(1,  'System Administrator', 'admin@lsbms.local',    '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000001', 'IGNOU Regional Centre 39, Noida',     'Noida',     '201301', 'admin',    'active'),

(2,  'Ramesh Kumar',         'ramesh.plumber@lsbms.local',  '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000002', 'Shastri Nagar, Meerut',               'Meerut',    '250004', 'provider', 'active'),
(3,  'Sunil Verma',          'sunil.electric@lsbms.local',  '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000003', 'Sector 62, Noida',                    'Noida',     '201309', 'provider', 'active'),
(4,  'Imran Sheikh',         'imran.actech@lsbms.local',    '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000004', 'Raj Nagar, Ghaziabad',                'Ghaziabad', '201002', 'provider', 'active'),
(5,  'Deepak Chauhan',       'deepak.carpenter@lsbms.local','$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000005', 'Abdullapur, Meerut',                  'Meerut',    '250001', 'provider', 'active'),
(6,  'Priya Sharma',         'priya.clean@lsbms.local',     '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000006', 'Sector 15, Noida',                    'Noida',     '201301', 'provider', 'active'),
(7,  'Mohit Saini',          'mohit.painter@lsbms.local',   '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000007', 'Karol Bagh, New Delhi',               'Delhi',     '110005', 'provider', 'active'),

(8,  'Gagan Sahay',          'gagan@example.com',    '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000008', '84/1 Nai Basti Abdullapur, Meerut',   'Meerut',    '250001', 'customer', 'active'),
(9,  'Anjali Gupta',         'anjali@example.com',   '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000009', 'Sector 50, Noida',                    'Noida',     '201301', 'customer', 'active'),
(10, 'Rakesh Yadav',         'rakesh@example.com',   '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000010', 'Vasundhara, Ghaziabad',               'Ghaziabad', '201012', 'customer', 'active'),
(11, 'Neha Singh',           'neha@example.com',     '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000011', 'Rohini Sector 7, New Delhi',          'Delhi',     '110085', 'customer', 'active'),
(12, 'Vikram Malhotra',      'vikram@example.com',   '$2y$10$BAkSoBz6PM5s/nKDHzYPzOqz6ELJvME9bGtw/sE73y.ZVR/KD0spC', '9811000012', 'Ganga Nagar, Meerut',                 'Meerut',    '250001', 'customer', 'active');


-- ---------------------------------------------------------------------
-- 3. PROVIDER PROFILES
--    Provider 6 (Mohit Saini) is deliberately left PENDING so that the
--    administrator has a live verification task to demonstrate.
-- ---------------------------------------------------------------------
INSERT INTO providers
    (provider_id, user_id, category_id, experience_years, hourly_rate, bio, skills, service_area,
     verification_status, verified_by, verified_at) VALUES
(1, 2, 1,  8, 350.00,
    'Licensed plumber serving Meerut for over eight years. Specialises in concealed pipeline leakage detection and bathroom refitting.',
    'Pipe fitting, Leak detection, Tap repair, Water tank cleaning, Bathroom fitting',
    'Meerut, Modinagar, Partapur', 'verified', 1, '2026-06-10 11:20:00'),

(2, 3, 2, 12, 450.00,
    'ITI certified electrician with twelve years across residential and small commercial sites. Inverter and solar wiring specialist.',
    'House wiring, Inverter installation, MCB and DB work, Fan and light fitting, Solar wiring',
    'Noida, Greater Noida, Sector 62-75', 'verified', 1, '2026-06-10 11:25:00'),

(3, 4, 3,  6, 500.00,
    'Authorised service technician for split and window air conditioners. Also repairs refrigerators and washing machines.',
    'AC servicing, Gas refilling, Refrigerator repair, Washing machine repair, Geyser repair',
    'Ghaziabad, Vaishali, Indirapuram', 'verified', 1, '2026-06-12 09:40:00'),

(4, 5, 4, 10, 400.00,
    'Furniture carpenter working with modular kitchens, wardrobes and custom woodwork. Own tools and transport.',
    'Modular kitchen, Wardrobe fitting, Door repair, Furniture polish, Custom woodwork',
    'Meerut, Sardhana, Mawana', 'verified', 1, '2026-06-12 09:45:00'),

(5, 6, 5,  4, 250.00,
    'Runs a four-member home cleaning team. Deep cleaning, kitchen degreasing and post-renovation clean-ups.',
    'Deep cleaning, Sofa shampooing, Kitchen degreasing, Bathroom sanitisation, Water tank cleaning',
    'Noida Sector 15-50, Film City', 'verified', 1, '2026-06-15 16:05:00'),

(6, 7, 6,  5, 300.00,
    'Interior and exterior painting with Asian Paints and Berger certified applicators. Texture and stencil work available.',
    'Interior painting, Exterior painting, Waterproofing, Texture work, Wood polish',
    'Delhi NCR, Karol Bagh, Paharganj', 'pending', NULL, NULL);


-- ---------------------------------------------------------------------
-- 4. WEEKLY AVAILABILITY  (1 = Monday ... 6 = Saturday; Sunday off)
-- ---------------------------------------------------------------------
INSERT INTO provider_availability (provider_id, day_of_week, start_time, end_time, is_available) VALUES
(1,1,'09:00:00','18:00:00',1),(1,2,'09:00:00','18:00:00',1),(1,3,'09:00:00','18:00:00',1),
(1,4,'09:00:00','18:00:00',1),(1,5,'09:00:00','18:00:00',1),(1,6,'09:00:00','14:00:00',1),

(2,1,'08:00:00','19:00:00',1),(2,2,'08:00:00','19:00:00',1),(2,3,'08:00:00','19:00:00',1),
(2,4,'08:00:00','19:00:00',1),(2,5,'08:00:00','19:00:00',1),(2,6,'08:00:00','19:00:00',1),

(3,1,'09:00:00','20:00:00',1),(3,2,'09:00:00','20:00:00',1),(3,3,'09:00:00','20:00:00',1),
(3,4,'09:00:00','20:00:00',1),(3,5,'09:00:00','20:00:00',1),(3,6,'09:00:00','20:00:00',1),

(4,1,'10:00:00','18:00:00',1),(4,2,'10:00:00','18:00:00',1),(4,3,'10:00:00','18:00:00',1),
(4,4,'10:00:00','18:00:00',1),(4,5,'10:00:00','18:00:00',1),(4,6,'10:00:00','16:00:00',1),

(5,1,'08:00:00','17:00:00',1),(5,2,'08:00:00','17:00:00',1),(5,3,'08:00:00','17:00:00',1),
(5,4,'08:00:00','17:00:00',1),(5,5,'08:00:00','17:00:00',1),(5,6,'08:00:00','17:00:00',1),

(6,1,'09:00:00','18:00:00',1),(6,2,'09:00:00','18:00:00',1),(6,3,'09:00:00','18:00:00',1),
(6,4,'09:00:00','18:00:00',1),(6,5,'09:00:00','18:00:00',1),(6,6,'09:00:00','18:00:00',1);


-- ---------------------------------------------------------------------
-- 5. SERVICES OFFERED
-- ---------------------------------------------------------------------
INSERT INTO services (service_id, provider_id, category_id, service_name, description, base_price, duration_minutes, is_active) VALUES
(1,  1, 1, 'Tap and mixer repair',            'Repair or replacement of leaking taps, mixers and diverters.',        350.00,  60, 1),
(2,  1, 1, 'Concealed leakage detection',     'Locate and repair concealed pipeline leakage without heavy breaking.', 900.00, 180, 1),
(3,  1, 1, 'Water tank cleaning',             'Complete drain, scrub and disinfection of overhead or underground tank.', 800.00, 120, 1),

(4,  2, 2, 'Switchboard and MCB repair',      'Fault finding and repair of switchboards, MCBs and distribution boards.', 450.00,  60, 1),
(5,  2, 2, 'Inverter installation',           'Supply-independent installation and wiring of home inverter and battery.', 1200.00, 180, 1),
(6,  2, 2, 'Fan and light fitting',           'Installation of ceiling fans, tube lights and decorative fixtures.',   400.00,  60, 1),

(7,  3, 3, 'Split AC service',                'Full jet service of indoor and outdoor units including filter cleaning.', 600.00,  90, 1),
(8,  3, 3, 'AC gas refilling',                'Leak test and R32/R410A gas top-up with pressure verification.',       2200.00, 120, 1),
(9,  3, 3, 'Refrigerator repair',             'Diagnosis and repair of cooling, compressor and thermostat faults.',    700.00,  90, 1),

(10, 4, 4, 'Furniture repair',                'Repair of beds, chairs, tables and cupboard hinges.',                  400.00,  90, 1),
(11, 4, 4, 'Wardrobe and door fitting',       'Fitting and alignment of wardrobe shutters, channels and doors.',      1500.00, 240, 1),

(12, 5, 5, 'Full home deep cleaning (2BHK)',  'Complete deep clean of a 2BHK including kitchen and bathrooms.',       2500.00, 300, 1),
(13, 5, 5, 'Sofa and carpet shampooing',      'Machine shampooing and vacuum extraction for sofas and carpets.',      1200.00, 120, 1),
(14, 5, 5, 'Bathroom deep sanitisation',      'Descaling, disinfection and stain removal for up to two bathrooms.',    900.00,  90, 1),

(15, 6, 6, 'Interior wall painting',          'Two-coat interior emulsion including putty and primer.',               3500.00, 480, 1);


-- ---------------------------------------------------------------------
-- 6. MAINTENANCE PLANS  [ MAINTENANCE MODULE ]
-- ---------------------------------------------------------------------
INSERT INTO maintenance_plans (plan_id, category_id, plan_name, description, frequency, visits_per_year, price, duration_months, is_active) VALUES
(1, 3, 'AC Quarterly Care',        'Four scheduled AC services a year -- filter clean, coil wash, gas pressure check and performance report.',  'quarterly',    4,  2400.00, 12, 1),
(2, 1, 'Plumbing Half-Yearly Shield','Two preventive plumbing inspections a year covering taps, traps, tank and concealed line pressure test.', 'half_yearly',  2,  1500.00, 12, 1),
(3, 5, 'Monthly Home Deep Clean',   'A scheduled deep clean every month, including kitchen degreasing and bathroom sanitisation.',              'monthly',     12,  9600.00, 12, 1),
(4, 2, 'Electrical Safety Audit',   'Half-yearly earthing, load and MCB safety audit with a written compliance report.',                        'half_yearly',  2,  1800.00, 12, 1);


-- ---------------------------------------------------------------------
-- 7. BOOKINGS
--    A spread across every status so each filter tab has content.
--    No booking falls on a Sunday, since no provider works then.
-- ---------------------------------------------------------------------
INSERT INTO bookings
    (booking_id, booking_code, user_id, provider_id, service_id, booking_date, booking_time,
     duration_minutes, service_address, city, pincode, problem_description, status,
     estimated_cost, final_cost, cancellation_reason, is_maintenance, created_at) VALUES

-- --- Completed jobs (history, ratings and revenue) --------------------
(1,  'LSB-2026-000001',  8, 1,  1, '2026-07-15', '10:00:00',  60, '84/1 Nai Basti Abdullapur, Meerut', 'Meerut',    '250001', 'Kitchen tap dripping continuously since last week.',              'completed',  350.00,  350.00, NULL, 0, '2026-07-13 18:22:00'),
(2,  'LSB-2026-000002',  9, 2,  4, '2026-07-22', '11:00:00',  60, 'B-402, Sector 50, Noida',          'Noida',     '201301', 'Main switchboard sparking when geyser is switched on.',           'completed',  450.00,  520.00, NULL, 0, '2026-07-20 09:15:00'),
(3,  'LSB-2026-000003', 10, 3,  7, '2026-08-03', '16:00:00',  90, 'Flat 12, Vasundhara, Ghaziabad',   'Ghaziabad', '201012', 'Split AC not cooling properly, water dripping indoors.',          'completed',  600.00,  600.00, NULL, 0, '2026-08-01 20:40:00'),
(4,  'LSB-2026-000004', 11, 5, 12, '2026-08-11', '09:00:00', 300, 'C-9, Rohini Sector 7, New Delhi',  'Delhi',     '110085', 'Deep cleaning required before relatives arrive.',                 'completed', 2500.00, 2500.00, NULL, 0, '2026-08-08 12:05:00'),
(5,  'LSB-2026-000005', 12, 4, 10, '2026-08-18', '11:00:00',  90, 'Ganga Nagar, Meerut',              'Meerut',    '250001', 'Wardrobe shutter hinge broken, door not closing.',                'completed',  400.00,  450.00, NULL, 0, '2026-08-16 17:30:00'),
(6,  'LSB-2026-000006',  8, 3,  8, '2026-08-25', '10:00:00', 120, '84/1 Nai Basti Abdullapur, Meerut','Meerut',    '250001', 'AC cooling weak, suspect gas leakage.',                           'completed', 2200.00, 2200.00, NULL, 0, '2026-08-22 08:10:00'),

-- --- Live pipeline ----------------------------------------------------
(7,  'LSB-2026-000007',  9, 3,  9, '2026-09-02', '14:00:00',  90, 'B-402, Sector 50, Noida',          'Noida',     '201301', 'Refrigerator freezer compartment not cooling at all.',            'in_progress', 700.00, NULL, NULL, 0, '2026-08-31 19:00:00'),
(8,  'LSB-2026-000008', 10, 2,  6, '2026-09-03', '10:00:00',  60, 'Flat 12, Vasundhara, Ghaziabad',   'Ghaziabad', '201012', 'Two ceiling fans to be installed in bedrooms.',                   'confirmed',   400.00, NULL, NULL, 0, '2026-09-01 11:45:00'),
(9,  'LSB-2026-000009', 11, 1,  3, '2026-09-04', '09:00:00', 120, 'C-9, Rohini Sector 7, New Delhi',  'Delhi',     '110085', 'Overhead tank cleaning, not done for over a year.',               'confirmed',   800.00, NULL, NULL, 0, '2026-09-01 15:20:00'),
(10, 'LSB-2026-000010', 12, 5, 13, '2026-09-05', '12:00:00', 120, 'Ganga Nagar, Meerut',              'Meerut',    '250001', 'Three-seater sofa shampooing and stain removal.',                 'pending',    1200.00, NULL, NULL, 0, '2026-09-02 08:30:00'),
(11, 'LSB-2026-000011',  8, 2,  5, '2026-09-07', '10:00:00', 180, '84/1 Nai Basti Abdullapur, Meerut','Meerut',    '250001', 'New inverter and battery to be installed and wired.',             'pending',    1200.00, NULL, NULL, 0, '2026-09-02 09:05:00'),
(12, 'LSB-2026-000012',  9, 4, 11, '2026-09-08', '11:00:00', 240, 'B-402, Sector 50, Noida',          'Noida',     '201301', 'Wardrobe shutters misaligned after shifting.',                    'pending',    1500.00, NULL, NULL, 0, '2026-09-02 10:12:00'),

-- --- Cancelled and rejected -------------------------------------------
(13, 'LSB-2026-000013', 10, 1,  2, '2026-08-20', '15:00:00', 180, 'Flat 12, Vasundhara, Ghaziabad',   'Ghaziabad', '201012', 'Seepage on bedroom wall, suspect concealed leak.',                'cancelled',   900.00, NULL, 'Customer resolved the issue through the building society plumber.', 0, '2026-08-18 14:00:00'),
(14, 'LSB-2026-000014', 11, 4, 10, '2026-08-28', '17:00:00',  90, 'C-9, Rohini Sector 7, New Delhi',  'Delhi',     '110085', 'Dining chair legs loose, need repair.',                           'rejected',    400.00, NULL, 'Location is outside my service area.', 0, '2026-08-26 21:15:00'),

-- --- Auto-raised from an AMC contract  [ MAINTENANCE MODULE ] ----------
(15, 'LSB-2026-000015',  8, 3,  7, '2026-09-09', '10:00:00',  90, '84/1 Nai Basti Abdullapur, Meerut','Meerut',    '250001', 'Scheduled quarterly AC service under AMC contract.',              'pending',     0.00, NULL, NULL, 1, '2026-09-02 07:00:00'),

-- --- Completed but NOT yet reviewed ------------------------------------
--     Deliberately left without a feedback row so the rating flow has
--     something to demonstrate: this job shows up under "Rate your
--     recent jobs" on the customer dashboard.
(16, 'LSB-2026-000016',  8, 4, 10, '2026-08-27', '15:00:00',  90, '84/1 Nai Basti Abdullapur, Meerut','Meerut',    '250001', 'Bedroom cupboard door hinge loose and rubbing on the frame.',     'completed',   400.00,  450.00, NULL, 0, '2026-08-25 19:40:00');


-- ---------------------------------------------------------------------
-- 8. BOOKING STATUS HISTORY  (audit trail for the completed jobs)
-- ---------------------------------------------------------------------
INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, remarks, changed_at) VALUES
(1, NULL,          'pending',     8, 'Booking created by customer.',            '2026-07-13 18:22:00'),
(1, 'pending',     'confirmed',   2, 'Accepted. Will carry replacement washer.','2026-07-14 08:05:00'),
(1, 'confirmed',   'in_progress', 2, 'Technician reached site.',                '2026-07-15 10:05:00'),
(1, 'in_progress', 'completed',   2, 'Tap washer and spindle replaced.',        '2026-07-15 10:55:00'),

(2, NULL,          'pending',     9, 'Booking created by customer.',            '2026-07-20 09:15:00'),
(2, 'pending',     'confirmed',   3, 'Accepted.',                               '2026-07-20 12:30:00'),
(2, 'confirmed',   'in_progress', 3, 'Started fault tracing.',                  '2026-07-22 11:10:00'),
(2, 'in_progress', 'completed',   3, 'Faulty MCB replaced, extra part charged.','2026-07-22 12:40:00'),

(3, NULL,          'pending',    10, 'Booking created by customer.',            '2026-08-01 20:40:00'),
(3, 'pending',     'confirmed',   4, 'Accepted.',                               '2026-08-02 07:55:00'),
(3, 'confirmed',   'in_progress', 4, 'Service started.',                        '2026-08-03 16:05:00'),
(3, 'in_progress', 'completed',   4, 'Jet service done, drain pipe unclogged.', '2026-08-03 17:20:00'),

(4, NULL,          'pending',    11, 'Booking created by customer.',            '2026-08-08 12:05:00'),
(4, 'pending',     'confirmed',   6, 'Accepted, team of four assigned.',        '2026-08-08 18:20:00'),
(4, 'confirmed',   'in_progress', 6, 'Team on site.',                           '2026-08-11 09:10:00'),
(4, 'in_progress', 'completed',   6, 'Deep clean finished, customer satisfied.','2026-08-11 14:05:00'),

(5, NULL,          'pending',    12, 'Booking created by customer.',            '2026-08-16 17:30:00'),
(5, 'pending',     'confirmed',   5, 'Accepted.',                               '2026-08-17 09:00:00'),
(5, 'confirmed',   'in_progress', 5, 'Work started.',                           '2026-08-18 11:05:00'),
(5, 'in_progress', 'completed',   5, 'Hinge and channel replaced.',             '2026-08-18 12:25:00'),

(6, NULL,          'pending',     8, 'Booking created by customer.',            '2026-08-22 08:10:00'),
(6, 'pending',     'confirmed',   4, 'Accepted, will bring gauge set.',         '2026-08-22 10:15:00'),
(6, 'confirmed',   'in_progress', 4, 'Leak test started.',                      '2026-08-25 10:05:00'),
(6, 'in_progress', 'completed',   4, 'Leak brazed and R32 refilled.',           '2026-08-25 12:00:00'),

(7,  NULL,        'pending',     9, 'Booking created by customer.',             '2026-08-31 19:00:00'),
(7,  'pending',   'confirmed',   4, 'Accepted.',                                '2026-09-01 08:20:00'),
(7,  'confirmed', 'in_progress', 4, 'Diagnosis in progress.',                   '2026-09-02 14:05:00'),

(8,  NULL,        'pending',    10, 'Booking created by customer.',             '2026-09-01 11:45:00'),
(8,  'pending',   'confirmed',   3, 'Accepted.',                                '2026-09-01 13:00:00'),

(9,  NULL,        'pending',    11, 'Booking created by customer.',             '2026-09-01 15:20:00'),
(9,  'pending',   'confirmed',   2, 'Accepted.',                                '2026-09-01 16:40:00'),

(10, NULL,        'pending',    12, 'Booking created by customer.',             '2026-09-02 08:30:00'),
(11, NULL,        'pending',     8, 'Booking created by customer.',             '2026-09-02 09:05:00'),
(12, NULL,        'pending',     9, 'Booking created by customer.',             '2026-09-02 10:12:00'),

(13, NULL,        'pending',    10, 'Booking created by customer.',             '2026-08-18 14:00:00'),
(13, 'pending',   'cancelled',  10, 'Cancelled by customer.',                   '2026-08-19 10:30:00'),

(14, NULL,        'pending',    11, 'Booking created by customer.',             '2026-08-26 21:15:00'),
(14, 'pending',   'rejected',    5, 'Outside service area.',                    '2026-08-27 07:50:00'),

(15, NULL,        'pending',     8, 'Auto-generated from AMC contract AMC-2026-000001.', '2026-09-02 07:00:00'),

(16, NULL,          'pending',     8, 'Booking created by customer.',            '2026-08-25 19:40:00'),
(16, 'pending',     'confirmed',   5, 'Accepted.',                               '2026-08-26 08:30:00'),
(16, 'confirmed',   'in_progress', 5, 'Work started.',                           '2026-08-27 15:05:00'),
(16, 'in_progress', 'completed',   5, 'Hinge replaced and door realigned.',      '2026-08-27 16:20:00');


-- ---------------------------------------------------------------------
-- 9. MAINTENANCE CONTRACTS  [ MAINTENANCE MODULE ]
-- ---------------------------------------------------------------------
INSERT INTO maintenance_contracts
    (contract_id, contract_code, user_id, provider_id, plan_id, start_date, end_date,
     next_due_date, visits_used, total_visits, amount_paid, service_address, status, created_at) VALUES
(1, 'AMC-2026-000001',  8, 3, 1, '2026-03-09', '2027-03-09', '2026-09-09', 2, 4, 2400.00, '84/1 Nai Basti Abdullapur, Meerut', 'active', '2026-03-07 10:00:00'),
(2, 'AMC-2026-000002',  9, 1, 2, '2026-04-06', '2027-04-06', '2026-10-06', 1, 2, 1500.00, 'B-402, Sector 50, Noida',           'active', '2026-04-04 14:30:00'),
(3, 'AMC-2026-000003', 11, 5, 3, '2026-08-03', '2027-08-03', '2026-09-03', 1,12, 9600.00, 'C-9, Rohini Sector 7, New Delhi',   'active', '2026-08-01 09:20:00');


-- ---------------------------------------------------------------------
-- 10. MAINTENANCE VISITS  [ MAINTENANCE MODULE ]
--     Contract 1: quarterly, 4 visits -- two done, one due, one ahead.
--     This is what the AMC visit-strip on the contract screen renders.
-- ---------------------------------------------------------------------
INSERT INTO maintenance_visits (contract_id, booking_id, visit_number, scheduled_date, completed_date, status, technician_remarks) VALUES
(1, NULL, 1, '2026-03-09', '2026-03-09', 'completed', 'First service done. Filters cleaned, gas pressure normal.'),
(1, NULL, 2, '2026-06-09', '2026-06-11', 'completed', 'Coil wash done. Slight gas top-up carried out.'),
(1, 15,   3, '2026-09-09', NULL,         'due',       NULL),
(1, NULL, 4, '2026-12-09', NULL,         'scheduled', NULL),

(2, NULL, 1, '2026-04-06', '2026-04-06', 'completed', 'All taps and traps checked. Tank pressure normal.'),
(2, NULL, 2, '2026-10-06', NULL,         'scheduled', NULL),

(3, NULL, 1, '2026-08-03', '2026-08-03', 'completed', 'First monthly deep clean completed.'),
(3, NULL, 2, '2026-09-03', NULL,         'due',       NULL),
(3, NULL, 3, '2026-10-03', NULL,         'scheduled', NULL),
(3, NULL, 4, '2026-11-03', NULL,         'scheduled', NULL),
(3, NULL, 5, '2026-12-03', NULL,         'scheduled', NULL),
(3, NULL, 6, '2027-01-03', NULL,         'scheduled', NULL),
(3, NULL, 7, '2027-02-03', NULL,         'scheduled', NULL),
(3, NULL, 8, '2027-03-03', NULL,         'scheduled', NULL),
(3, NULL, 9, '2027-04-03', NULL,         'scheduled', NULL),
(3, NULL,10, '2027-05-03', NULL,         'scheduled', NULL),
(3, NULL,11, '2027-06-03', NULL,         'scheduled', NULL),
(3, NULL,12, '2027-07-03', NULL,         'scheduled', NULL);


-- ---------------------------------------------------------------------
-- 11. FEEDBACK  (only against completed bookings)
-- ---------------------------------------------------------------------
INSERT INTO feedback (booking_id, user_id, provider_id, rating, comments, is_approved, created_at) VALUES
(1,  8, 1, 5, 'Came on time and fixed the tap in twenty minutes. Very neat work, no mess left behind.',                    1, '2026-07-15 19:30:00'),
(2,  9, 2, 4, 'Good diagnosis and the sparking stopped. Charged a little extra for the MCB but explained it clearly.',      1, '2026-07-23 08:10:00'),
(3, 10, 3, 5, 'Excellent AC service. Cooling improved noticeably and he also cleared the blocked drain pipe at no charge.', 1, '2026-08-04 10:00:00'),
(4, 11, 5, 5, 'The team was thorough and polite. The kitchen looks brand new. Will book again before Diwali.',              1, '2026-08-12 09:45:00'),
(5, 12, 4, 4, 'Repaired the hinge properly. Arrived about half an hour late but informed me in advance.',                   1, '2026-08-19 07:20:00'),
(6,  8, 3, 5, 'Found the leak quickly and refilled the gas. Cooling is back to normal. Fair pricing.',                      1, '2026-08-26 18:15:00');


-- ---------------------------------------------------------------------
-- 12. PAYMENTS  (settlement is simulated -- see synopsis exclusions)
-- ---------------------------------------------------------------------
INSERT INTO payments (booking_id, invoice_no, amount, payment_mode, payment_status, transaction_ref, paid_at) VALUES
(1, 'INV-2026-000001',  350.00, 'cash',       'paid', NULL,                 '2026-07-15 11:00:00'),
(2, 'INV-2026-000002',  520.00, 'upi',        'paid', 'UPI2607221240XXXX',  '2026-07-22 12:45:00'),
(3, 'INV-2026-000003',  600.00, 'upi',        'paid', 'UPI2608031725XXXX',  '2026-08-03 17:25:00'),
(4, 'INV-2026-000004', 2500.00, 'card',       'paid', 'CARD260811140XXXX',  '2026-08-11 14:10:00'),
(5, 'INV-2026-000005',  450.00, 'cash',       'paid', NULL,                 '2026-08-18 12:30:00'),
(6, 'INV-2026-000006', 2200.00, 'netbanking', 'paid', 'NB2608251205XXXX',   '2026-08-25 12:05:00'),
(7, 'INV-2026-000007',  700.00, 'cash',    'pending', NULL,                 NULL),
(16,'INV-2026-000016',  450.00, 'cash',       'paid', NULL,                 '2026-08-27 16:30:00');


-- ---------------------------------------------------------------------
-- 13. NOTIFICATIONS
-- ---------------------------------------------------------------------
INSERT INTO notifications (user_id, title, message, link, icon, is_read, created_at) VALUES
(1,  'New professional awaiting verification', 'Mohit Saini has registered and needs approval.',                   'admin/providers.php?status=pending',  'shield', 0, '2026-09-01 10:00:00'),
(8,  'Maintenance visit due',                  'Your quarterly AC service under AMC-2026-000001 is due on 09 Sep 2026.', 'customer/maintenance.php',       'wrench', 0, '2026-09-02 07:00:00'),
(8,  'Booking confirmed',                      'Ramesh Kumar accepted your water tank cleaning request.',           'customer/my-bookings.php',           'check',  1, '2026-09-01 16:40:00'),
(9,  'Work in progress',                       'Imran Sheikh has started work on LSB-2026-000007.',                'customer/my-bookings.php',           'tool',   0, '2026-09-02 14:05:00'),
(4,  'New booking request',                    'Gagan Sahay requested a scheduled AC service on 09 Sep 2026.',     'provider/requests.php',              'inbox',  0, '2026-09-02 07:00:00'),
(6,  'New booking request',                    'Vikram Malhotra requested sofa shampooing on 05 Sep 2026.',        'provider/requests.php',              'inbox',  0, '2026-09-02 08:30:00'),
(11, 'Maintenance visit due',                  'Your monthly deep clean under AMC-2026-000003 is due on 03 Sep 2026.', 'customer/maintenance.php',        'wrench', 0, '2026-09-02 06:00:00');


-- ---------------------------------------------------------------------
-- 14. ACTIVITY LOG  (a short security trail for the admin screen)
-- ---------------------------------------------------------------------
INSERT INTO activity_log (user_id, action, entity, entity_id, details, ip_address, created_at) VALUES
(1,    'login_success',        'users',    1,    'Role: admin',                    '127.0.0.1', '2026-09-01 09:55:00'),
(1,    'provider_verified',    'providers', 5,   'Priya Sharma approved',          '127.0.0.1', '2026-06-15 16:05:00'),
(NULL, 'login_failed',         'auth',     NULL, 'Unknown email: test@test.com',   '127.0.0.1', '2026-09-01 22:14:00'),
(8,    'login_success',        'users',    8,    'Role: customer',                 '127.0.0.1', '2026-09-02 08:25:00'),
(8,    'booking_created',      'bookings', 11,   'LSB-2026-000011',                '127.0.0.1', '2026-09-02 09:05:00'),
(4,    'login_success',        'users',    4,    'Role: provider',                 '127.0.0.1', '2026-09-02 13:58:00'),
(4,    'booking_status_change','bookings', 7,    'confirmed -> in_progress',       '127.0.0.1', '2026-09-02 14:05:00');


-- ---------------------------------------------------------------------
-- 15. SYNCHRONISE DERIVED COLUMNS
-- ---------------------------------------------------------------------
--  providers.avg_rating, total_reviews and total_jobs are denormalised
--  caches. Rather than hard-coding them above -- which would let the
--  seed data contradict itself -- they are computed here from the rows
--  actually inserted. This is the same statement the application runs
--  after every new review, so the seeded state is reachable by the
--  running system.
-- ---------------------------------------------------------------------
UPDATE providers p
   SET p.avg_rating = COALESCE((
           SELECT ROUND(AVG(f.rating), 2)
             FROM feedback f
            WHERE f.provider_id = p.provider_id AND f.is_approved = 1), 0),
       p.total_reviews = (
           SELECT COUNT(*)
             FROM feedback f
            WHERE f.provider_id = p.provider_id AND f.is_approved = 1),
       p.total_jobs = (
           SELECT COUNT(*)
             FROM bookings b
            WHERE b.provider_id = p.provider_id AND b.status = 'completed');

-- Keep AUTO_INCREMENT counters ahead of the explicit ids used above.
ALTER TABLE users                 AUTO_INCREMENT = 13;
ALTER TABLE categories            AUTO_INCREMENT = 9;
ALTER TABLE providers             AUTO_INCREMENT = 7;
ALTER TABLE services              AUTO_INCREMENT = 16;
ALTER TABLE bookings              AUTO_INCREMENT = 17;
ALTER TABLE maintenance_plans     AUTO_INCREMENT = 5;
ALTER TABLE maintenance_contracts AUTO_INCREMENT = 4;

-- =====================================================================
--  END OF SEED DATA
--  Sign in with any seeded email and the password  Lsbms@2026
--    Administrator : admin@lsbms.local
--    Provider      : imran.actech@lsbms.local
--    Customer      : gagan@example.com
-- =====================================================================
