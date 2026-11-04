# iMasons Data Acquisition

Live data collection at iMasons events: attendees respond on their phones, results
appear on the room's big screen, and the host runs everything from a phone remote.

## Pages

| Page | Who | What |
|---|---|---|
| `admin.php` | Host (password) | Create events, add modules, edit content, order the run of show, export CSV |
| `remote.php?c=CODE` | Host (password) | Next/previous, go to page N, go live, show/hide slides, open/close, approve statements |
| `screen.php?c=CODE` | Room screen | Live slide with QR code; read-only |
| `e.php?c=CODE` | Attendees | One link per event; follows whatever is live |

## Modules

1. **Yes / No / Don't know** – slides: *Live participation*, *Results*. On phones each statement is a card:
   swipe right = Yes, left = No, down = Don't know (buttons and arrow keys too). Statements come in random order
   for each attendee (ones still collecting answers first, then ones needing revision). A statement stops being
   asked once it has the minimum number of answers (default 8) and enough agreement (default 75%)
   on Yes or No. Otherwise it is flagged *Needs revision*. Rewording a statement restarts its count.
2. **Text input** – slides: *Live statements*, *Statement wall*. Optional multiple-choice questions.
   Statements appear on screen only after approval on the remote. The live page shows the prompt and one answer
   at a time: a newly approved answer comes next, each stays up at least 10 s, then the page cycles through all
   of them (fade out / fade in). The wall shows 8 answers and swaps one random card at a time.
3. **Send me the collateral** – one per event, switched on/off in the event settings (not in the module list).
   On: a *Sign-ups* page is added to the run of show, and every attendee page always shows an email banner that
   opens a name/email/consent pop-up. After submitting, the attendee returns to the page they were on and the banner
   turns grey to confirm they will receive the materials. Off: the page is removed; sign-ups collected so far are kept.
4. **Pledge – get me connected** – slide: *Pledges*. Pledge text, name, email, consent.

### Uploaded slides

Upload a deck from the event page as a **PDF** (PowerPoint: File → Save As → PDF) or as PNG/JPG images
(File → Export → PNG). The admin's browser renders each page to an image (bundled pdf.js), so nothing needs
installing on the server; pages are stored in the database. Choose where the slides go in the run of show,
then drag (☰) to fine-tune. Uploading a revised version of a deck keeps its slides in place.
Animations and embedded video are not carried over.

On the big screen, slides fill the screen and every page change cross-fades. When the host is logged in on the laptop running the screen,
a click or →/Space/PageDown moves forward and ← /PageUp moves back (presenter clickers work too).
Hide a slide (e.g. a placeholder) and Next/Previous skip it.

5. **Quick-fire cards** – slides: *Live feedback*, *Results*. Yes / No / Don't know on short statements; Yes always
   means "this is a problem". Statements are imported per module from CSV in the admin (`id, theme, topic, stage,
   statement, core_set`, optional `is_check`), so the module can be reused with new statements; check statements
   Q901–Q904 are added automatically. Phones: a two-question profile (side, optional segment), then one card at a time,
   least-answered statements first (random ties), a check card about every 25 cards (at most 2), pause and resume.
   *Live feedback* shows participation only (no statement text). *Results* has four tabs (keys 1–4; the clicker moves
   through the tabs before the next slide): top problems by **lift**, where it breaks (by stage), blind spots
   (Don't know), side by side (only with 8+ people per side and min n on both). All calculations are in
   `qf_compute()` in `src/quickfire.php`, unit-tested by `php tests/qf_compute_test.php`.

Hiding a *Live participation* slide also hides that module's results slide; Next/Previous skip hidden slides.

## Deployment

Push to `main` → Plesk pulls → live at `/imasons_session_running_platform/public/`.
Database migrations in `src/schema.php` run automatically on the next page load.
Server settings live in `imasons_config.php` (see `config/imasons_config.example.php`), never in Git.

Built for PHP 7.4+ and MariaDB 10.3+. No build step; real-time updates use polling.

### How the devices talk to the server

Shared hosting limits how many PHP requests can run **at the same time**, not how many it can serve in total.
So nothing keeps a connection open, and no device sends a new request before its last one has been answered.

| Device | What it does |
|---|---|
| Attendee phone | Asks what is live every 10 s (`phone_check_s` in the config, 2–60), spread randomly so a room never asks in sync, and at once when tapped, unlocked or brought back to the page. Each answer is its own short request from a queue saved on the phone, so swiping never waits and nothing is lost on bad Wi-Fi; answers given while a request is still on its way travel together in the next one. Yes/No statements are shuffled on the phone. Requests are sent with `Connection: close`. |
| Big screen | Short request every 0.4 s (every 10 s in a hidden tab); unchanged replies are ~30 bytes. Key presses and clicks on the laptop switch between uploaded slides instantly (they are preloaded) and tell the server in the background; data collection pages appear as soon as the server answers. Slide to slide is an instant cut; to or from a data collection page, a 0.25 s fade. "Open big screen" always reuses the same tab. |
| Remote | Short request every 2 s, waiting for each reply. Buttons send one request each. |
| All | On errors they wait longer between tries (up to 30–60 s) instead of retrying faster, and show "Reconnecting". |

Answer requests do only the write (one query to identify the phone, one insert per answer). In a local test
with the PHP worker limit set to 4 and a 10 ms link to the database, 80 phones swiping every ~3 s were answered
in 45 ms typically with at most 10 requests running at once; the previous design reached 1,400 queued requests
and 15 s waits under the same load. Set `IMASONS_DEBUG=1` in the environment to get an `X-Query-Count` header on API responses.
