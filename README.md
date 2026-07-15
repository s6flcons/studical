# StudiCal

A collaborative event calendar built with **Symfony** for the WebTech SS 2026 project assignment.

Every registered user automatically owns one **private calendar**, visible only to them. Any user can create **public calendars**, which every registered user can see and post events to. Unauthenticated visitors cannot see any calendar.

---

## Running the project

Requirements: PHP 8.2+, Composer, Symfony CLI, SQLite PHP extensions (`pdo_sqlite`, `sqlite3`).

```bash
git clone <repo-url> && cd studical
composer install
```

Create `.env.local` in the project root:

```
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
```

Then:

```bash
php bin/console doctrine:migrations:migrate
symfony server:start
```

Open https://127.0.0.1:8000 and register an account.

---

## Data model

```
User 1 ──── * Calendar          (owner)
Calendar 1 ──── * Event         (calendar; orphanRemoval: deleting a calendar deletes its events)
User 1 ──── * Event             (creator)
```

There is **no separate "private calendar" entity**: a `Calendar` with `isPublic = false` *is* a private calendar. One is created automatically for every user at registration. Public calendars are the same entity with `isPublic = true`. The `creator` relation on `Event` (distinct from the calendar's `owner`) records who authored each event.

`Event` fields: `title` (required), `startAt` (required), `endAt`, `description`, `location` (all optional) — satisfying the MUST minimum of "title and date and time" while leaving room for the SHOULD-level extra fields.

---

## How each MUST requirement is fulfilled — and which Symfony concepts it demonstrates

### 1. "Users must register and authenticate themselves"

**Symfony concepts: Security (authentication), Forms, Doctrine ORM, password hashing, service container**

- `App\Entity\User` implements `UserInterface` / `PasswordAuthenticatedUserInterface`; the email is the user identifier (configured as the user provider in `config/packages/security.yaml`).
- **Registration** (`RegistrationController` + `RegistrationFormType`): a Symfony Form with a `plainPassword` field that is deliberately **not mapped** to the entity — the plaintext never touches the database. `UserPasswordHasherInterface` (injected via the **service container** through constructor-less action injection) hashes it with the `auto` algorithm before persisting. A `#[UniqueEntity]` constraint (Validator component) prevents duplicate accounts.
- **Login/logout**: Symfony's built-in `form_login` authenticator, configured declaratively in `security.yaml` (`login_path`, `check_path`, `enable_csrf: true`, `default_target_path: app_home`) — no custom authenticator class needed. Logout is a pure config route (`app_logout`); the controller method body is never executed.
- CSRF protection is active on the login form and all delete actions.

*Presentation-worthy code:* `RegistrationController::register()` — form handling lifecycle (`createForm` → `handleRequest` → `isSubmitted && isValid` → persist/flush), plus the private-calendar creation (below) in the same transaction.

### 2. "Every user has access to their own private calendar; private events are not visible to other users"

**Symfony concepts: Doctrine ORM (relations, transactions), authorisation in controller logic**

- On successful registration, the controller creates a `Calendar` (`isPublic = false`, `owner = the new user`) and persists it **in the same `flush()`** as the user — Doctrine wraps both inserts in one transaction and resolves the FK insert order itself, so a user can never exist without their private calendar.
- Visibility is enforced **in controllers, not just templates**:
  - `CalendarController::show()` denies access unless the calendar is public or owned by the current user.
  - `EventController::edit()/delete()` call a shared guard (`denyAccessUnlessEventVisible()`) that applies the same rule to the event's calendar — closing the "direct URL to someone else's private event" hole we found in testing.

*Presentation-worthy code:* `denyAccessUnlessEventVisible()` — a concrete example of object-level authorisation beyond `IsGranted`, and a good story to tell (we found the leak by testing as two users).

### 3. "All registered users see all available public calendars / users may create public calendars visible to everyone"

**Symfony concepts: Routing, controllers, Doctrine repositories, Twig, MakerBundle CRUD**

- `CalendarController` (scaffolded with `make:crud`, then customized):
  - `index()` lists `findBy(['isPublic' => true])` — the repository filter guarantees private calendars never leak into the list, regardless of template code.
  - `new()` renders a `CalendarType` form containing **only** the `name` field. `isPublic = true` and `owner = current user` are forced server-side in the controller — the client cannot tamper with either, even with a crafted POST.
  - Attribute routing throughout (`#[Route('/calendar', ...)]` prefix on the class, per-action paths and names).
- Twig templates (`calendar/index.html.twig`, `show.html.twig`) render the data; they extend a shared `base.html.twig` with a session-aware navigation bar (`app.user` Twig global).

*Presentation-worthy code:* `CalendarController::new()` — the "never trust the form for ownership/visibility" pattern.

### 4. "No unauthenticated visitor may see any calendar"

**Symfony concepts: security attributes, firewall, access control in controller logic**

- Every calendar/event/dashboard controller carries a **class-level** `#[IsGranted('IS_AUTHENTICATED_FULLY')]`, covering all its actions at once. This is controller-level enforcement, exactly as the assignment requests — not link-hiding in Twig.
- The `main` firewall in `security.yaml` handles session authentication; anonymous requests to protected routes are redirected to `/login`, and Symfony's target-path mechanism returns the user to the originally requested page after login.
- A public landing page at `/` (separate `DefaultController`, intentionally outside the protected controllers) offers Login/Register and redirects already-authenticated users to their dashboard.

### 5. "Authenticated users must be able to: create private events, create events in any public calendar, set up public calendars, change and delete events"

**Symfony concepts: routing with parameters, param converters, forms, CSRF, Doctrine relations**

- **Creating events is calendar-scoped by design**: the route is `/calendar/{calendarId}/event/new`. One controller action serves both cases — the only difference between "private event" and "public event" is which calendar id is in the URL. The action loads the calendar, checks the user may post to it (public, or their own), and stamps `calendar` + `creator` server-side. `EventType` exposes only `title`, `startAt`, `endAt`, `description`, `location`.
- **Editing** (`/event/{id}/edit`) and **deleting** (`POST /event/{id}` with CSRF token) resolve the `Event` automatically from the URL (entity value resolver / param converter — `public function edit(Request $request, Event $event, ...)`), run the visibility guard, and redirect back to the event's calendar page via the `event.calendar` relation.
- There is deliberately **no global event index or standalone event page** — a `findAll()` over events would leak private events across users. Events are always viewed in the context of their calendar (`{% for event in calendar.events %}` iterates the Doctrine relation lazily in Twig).

*Presentation-worthy code:* `EventController::new()` — routing parameter → repository lookup → authorisation check → server-side relation stamping → form lifecycle, all in ~30 lines.

### 6. "Events must minimally consist of an event title and date and time"

**Symfony concepts: Doctrine column mapping, form types**

- `title` (string, NOT NULL) and `startAt` (`datetime_immutable`, NOT NULL) are enforced at the database level via `#[ORM\Column]` mapping and in the form (HTML5 `datetime-local` widget via `'widget' => 'single_text'`).
- `endAt`, `description`, `location` are nullable — optional extras per the SHOULD requirements, without blocking the minimal event.

---

## Symfony ecosystem checklist (for the Fragerunde)

| Concept | Where it's used |
|---|---|
| **Routing** | PHP attributes on every controller; class-level prefixes; parameterized routes (`/calendar/{calendarId}/event/new`); named routes + `redirectToRoute()` |
| **Controllers** | `RegistrationController`, `SecurityController`, `HomeController`, `DefaultController`, `CalendarController`, `EventController` — thin actions, logic on entities/repositories |
| **Twig** | Template inheritance (`base.html.twig`), blocks, `path()`, the `app.user` global, filters (`date`), loops over Doctrine collections, conditional rendering by permission |
| **Service container / DI** | Autowired action injection everywhere: `EntityManagerInterface`, `UserPasswordHasherInterface`, repositories. Zero manual service configuration — autoconfiguration does it |
| **Doctrine ORM** | 3 entities, ManyToOne/OneToMany relations with inverse sides, `orphanRemoval` (calendar → events), repository `findBy()` filters, migrations for every schema change |
| **Forms** | `RegistrationFormType`, `CalendarType`, `EventType`; unmapped fields (`plainPassword`); deliberately *removed* fields (`owner`, `creator`, `isPublic`) as a security measure |
| **Authentication** | `make:user` entity, `form_login` firewall, password hashing (`auto`), CSRF on login, logout route, post-login redirect + target-path behavior |
| **Authorisation** | `#[IsGranted]` at class level + object-level checks in controller code (`createAccessDeniedException`) — enforced in controllers, not only templates, as the assignment requires |
| **Validation** | `#[UniqueEntity]` on User; NOT NULL constraints surfacing through form validation |
| **Migrations** | Full history in `migrations/`; schema never edited by hand |

---

## Deliberate scope decisions

Documented per the assignment's requirement to show honest, verifiable engagement:

- **Permissions on public calendars**: any authenticated user may currently edit/delete any event on a *public* calendar. The stricter creator-or-calendar-owner rule is a SHOULD, not a MUST. The codebase is prepared for it: adding it later is purely additive (an `EventVoter` + `#[IsGranted('EVENT_EDIT', subject: 'event')]` on two actions) with no restructuring, because `Event.creator` and `Calendar.owner` are already modeled.
- **List view instead of a calendar grid** (SHOULD): events are listed per calendar in a table.
- **No email verification** at registration: not required, avoids mailer configuration.
- **Calendars cannot be edited/deleted**: the assignment only requires changing/deleting *events*; the generated CRUD actions for calendars were intentionally removed rather than left unsecured.
- **SQLite** as the database: zero-setup, file-based, fully sufficient for the project scope.

## Known limitations

- Session behavior when rapidly switching between test accounts in one browser can be inconsistent; a private window or fresh login resolves it.
- `symfony server:start -d` (background mode) is unreliable on Windows — run the server in the foreground.
