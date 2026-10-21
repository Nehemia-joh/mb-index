# Maisha Bora Autism Rating Scale

A PHP web application for scoring the Maisha Bora Autism Rating Scale — Third
Edition, with user accounts, stored records and activity tracking.

## Features

- **Section 1**: Identifying information form (required fields enforced)
- **Section 2**: Rate all items across 6 subscales (0-3 scale)
  - Restricted/Repetitive Behaviors (RB), Social Interaction (SI),
    Social Communication (SC), Emotional Responses (ER),
    Cognitive Style (CS), Maladaptive Speech (MS)
- **Mute Feature**: If the individual is mute, CS and MS subscales are excluded
  and only 4 subscales are used for composite scoring
- **Table A**: Raw scores converted to percentile ranks and scaled scores
- **Section 3**: Composite scoring (sum of scaled scores) with Table B lookup
- **Section 4**: Interpretation guide (probability of ASD, DSM-5 severity level)
- **Database**: Every completed assessment is stored as a record
- **Users & login**: therapist / administrator / IT system administrator accounts
  with hashed passwords
- **Tracking**: every page move and every change is recorded per login session

## Installation

Serve the repository as the document root (or a subfolder) on any PHP 8+
server with MySQL (cPanel works fine):

```bash
php setup.php                            # creates tables + default admin
php -S localhost:8099 route.php          # local development server
```

Then open `http://localhost:8099` and log in with the default administrator
credentials from the `.envmb-index` file.

## Configuration (.envmb-index)

The `.envmb-index` file lives **ONE STEP BEHIND the project folder** (e.g. project in
`/home/user/Gilliam` ⇒ `.envmb-index` in `/home/user/.envmb-index`), so it is never
web-accessible. See `.env.example` for all keys:

- `DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS` — MySQL credentials
  (the system uses MySQL only — the schema is created by `setup.php`
  or by importing `database.sql` in phpMyAdmin)
- `DEFAULT_ADMIN_USER / DEFAULT_ADMIN_PASSWORD / DEFAULT_ADMIN_ROLE` —
  seeded once by `setup.php`
- `SESSION_TIMEOUT` — idle sessions are closed automatically on `/itadmin`

## Clean URLs

`route.php` + `.htaccess` map short paths to files, domain-agnostic
(works in any document root or subfolder):

```
/admin      ->  admin.php        (administrator dashboard)
/itadmin    ->  itadmin.php      (IT system administrator console)
/login      ->  login.php
/register   ->  register.php     (create user accounts)
/index      ->  index.php        (the rating form)
/score      ->  score.php        (scoring + saving a record)
/records    ->  assessments.php  (stored records)
/assessment ->  assessment.php   (one stored record, ?id=)
```

## Data rules

- Passwords are hashed with `password_hash()`; nothing is stored in plain text.
- **Data cannot be saved when required fields are missing** — `score.php`
  validates Section 1 server-side and returns a 422 page listing the missing
  fields; nothing is written to the database in that case.
- All page-to-page movements and changes land in `user_sessions` and
  `activity_logs`.

## Database schema

`database.sql` is the MySQL schema for cPanel/phpMyAdmin import (or let
`setup.php` create the tables):

- `users` — accounts, roles, hashed passwords
- `user_sessions` — one row per login: current page, previous page, page views
- `activity_logs` — audit trail (login, logout, user_create, create_assessment…)
- `assessments` — one row per scored scale, including Autism Index results

## Scoring Algorithm

1. **Raw Scores**: Sum of item ratings (0-3) for each subscale
2. **Table A**: Raw scores are converted to scaled scores and percentile ranks
3. **Composite**: Sum of scaled scores (4 or 6 subscales depending on mute status)
4. **Table B**: Sum of scaled scores gives percentile rank and Autism Index
5. **Interpretation**: Autism Index → probability of ASD and DSM-5 severity level
