# Local Service Booking &amp; Management System

A web application that connects households with verified local service
professionals — plumbers, electricians, carpenters, AC technicians and cleaners —
covering the whole lifecycle of a job from search through to feedback, plus
recurring Annual Maintenance Contracts.

Built as the final-semester project for **BCSP-064**, Bachelor of Computer
Applications, Indira Gandhi National Open University.

| | |
|---|---|
| **Student** | Gagan Sahay |
| **Enrolment No.** | 2400652732 |
| **Programme** | BCA (Revised Syllabus), 6th Semester |
| **Course code** | BCSP-064 |
| **Project guide** | Soumik Laik |
| **Regional Centre** | 39 — Noida |
| **Study Centre** | 07107 — Maharaja Agrasen College |

---

## The problem

Hiring local help is still done by word of mouth and phone calls. There is no
record of who did what, no way to check whether a tradesperson is reliable, and
no protection against double bookings — providers keep their schedules in their
heads or in paper diaries. Recurring servicing (AC, plumbing, electrical safety)
gets remembered only after something breaks.

This system replaces that with a searchable directory of verified professionals,
a booking engine that will not let two jobs collide, a status trail every party
can see, and maintenance contracts that schedule themselves.

---

## Modules

| # | Module | What it does |
|---|---|---|
| 1 | **Authentication &amp; Account** | Registration, sign-in, role routing, bcrypt passwords, profile management |
| 2 | **Admin** | Dashboard, category management, professional verification, account suspension |
| 3 | **Service Provider** | Profile, service catalogue, weekly availability, job queue |
| 4 | **Customer** | Search, provider profiles, booking, booking history |
| 5 | **Booking Management** | Slot conflict detection, status workflow, immutable audit trail |
| 6 | **Maintenance (AMC)** | Recurring contracts, auto-generated visit schedules, due-date rollover |
| 7 | **Feedback &amp; Rating** | Post-completion reviews, rating recalculation, admin moderation |
| 8 | **Payment &amp; Invoice** | Invoice generation, payment recording, printable invoices |
| 9 | **Notification** | In-app notifications on every status change |
| 10 | **Reports** | Five management reports with CSV export and print layout |
| 11 | **Search &amp; Filter** | Filter by trade, city, rating and hourly rate |

---

## Technology

| Layer | Used |
|---|---|
| Front end | HTML5, CSS3 (hand-written, no framework), vanilla JavaScript (ES6) |
| Back end | PHP 8.2 with PDO |
| Database | MySQL 8.0 / MariaDB 10.4 — 14 tables in 3NF |
| Web server | Apache 2.4 |
| Local environment | XAMPP |

These are exactly the tools listed on the approved project proforma. No
front-end framework, ORM or package manager is used — every line is written for
this project.

---

## Database

14 tables in Third Normal Form with full referential integrity:

```
users ──┬── providers ──┬── provider_availability
        │               ├── services
        │               └── maintenance_contracts ── maintenance_visits
        │
        └── bookings ────┬── booking_status_history
                         ├── feedback
                         └── payments

categories ── maintenance_plans        notifications      activity_log
```

- **19 CHECK constraints** enforcing domain rules at the database level
- **Foreign keys with explicit referential actions** (`CASCADE`, `RESTRICT`, `SET NULL`)
- **Two reporting views** — `vw_provider_directory`, `vw_category_performance`
- Composite index on `(provider_id, booking_date, booking_time)` so the
  slot-conflict query is served from the index rather than a table scan

`avg_rating`, `total_reviews` and `total_jobs` on `providers` are deliberately
denormalised caches, recalculated inside the same transaction that writes the
feedback — so they cannot drift from the authoritative rows.

---

## Two pieces of logic worth pointing out

**Booking conflicts are an interval-overlap test, not an equality test.** Two
bookings clash whenever `newStart < existingEnd AND existingStart < newEnd`. A
10:00 two-hour job therefore correctly blocks an 11:00 booking, which a naive
`WHERE booking_time = :time` check would wrongly allow.

**Maintenance visits are generated from a fresh date each iteration.** Repeatedly
calling `modify('+3 months')` on one `DateTime` accumulates month-end overflow
(31 Jan + 1 month lands on 3 Mar), which would drag a twelve-visit schedule badly
off over a year.

---

## Security

- **PDO prepared statements everywhere**, with `ATTR_EMULATE_PREPARES => false`
  so query text and data travel separately and user input can never be parsed as SQL
- **bcrypt** password hashing via `password_hash()`; no plaintext password exists anywhere
- **CSRF tokens** on every state-changing form, compared with `hash_equals()`
- **`session_regenerate_id(true)`** on sign-in, defeating session fixation
- **Server-side role guards** on every protected page — hiding a menu link is not access control
- **Output escaping** on every rendered value; content is stored raw and escaped at render
- **Layered upload validation** — MIME sniffing, `getimagesize()`, server-generated filenames,
  and PHP execution switched off inside the uploads directory
- **Generic sign-in errors** with a constant-work dummy hash check, so response
  timing cannot reveal which email addresses hold accounts
- `config/`, `includes/` and `database/` are not reachable over HTTP

---

## Running it locally (XAMPP)

1. Install [XAMPP](https://www.apachefriends.org/) (PHP 8.2 or later).
2. Copy this folder into `C:\xampp\htdocs\lsbms`, or point an Apache `Alias` at it.
3. Start **Apache** and **MySQL** from the XAMPP Control Panel.
4. Open <http://localhost/phpmyadmin> and import, in this order:
   - `database/lsbms_schema.sql`
   - `database/lsbms_seed.sql`
5. Visit <http://localhost/lsbms>.

No configuration is needed — with no environment variables set, the application
falls back to XAMPP's defaults (`127.0.0.1`, user `root`, no password).

### Demonstration accounts

All seeded accounts use the password **`Lsbms@2026`**.

| Role | Email | Has waiting for them |
|---|---|---|
| Administrator | `admin@lsbms.local` | A professional pending verification |
| Professional | `imran.actech@lsbms.local` | An AMC job request |
| Customer | `gagan@example.com` | A completed job awaiting a rating |

Re-import `lsbms_schema.sql` then `lsbms_seed.sql` at any time to reset the demo data.

---

## Running it with Docker

```bash
cp .env.example .env      # then edit .env and set real passwords
docker compose up --build
```

The database schema and demo data are imported automatically the first time the
volume is created. Subsequent restarts leave your data alone.

---

## Project structure

```
├── config/          Application configuration and the PDO connection
├── includes/        Shared library: helpers, auth guards, page shell
├── database/        Schema DDL and demonstration seed data
├── assets/          Stylesheet, JavaScript, uploads
├── auth/            Registration, sign-in, sign-out
├── admin/           Administrator screens
├── provider/        Service professional screens
├── customer/        Customer screens
├── ajax/            JSON endpoints for slot checking and notifications
└── index.php        Public landing page
```

---

## Academic declaration

This project is original work carried out by Gagan Sahay (Enrolment No.
2400652732) under the guidance of Soumik Laik, submitted to the Indira Gandhi
National Open University in partial fulfilment of the requirements for the
degree of Bachelor of Computer Applications. It has not been submitted to this or
any other university or institute for any other course of study.
