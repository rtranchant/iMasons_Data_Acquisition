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

Hiding a *Live participation* slide also hides that module's results slide; Next/Previous skip hidden slides.

## Deployment

Push to `main` → Plesk pulls → live at `/imasons_session_running_platform/public/`.
Database migrations in `src/schema.php` run automatically on the next page load.
Server settings live in `imasons_config.php` (see `config/imasons_config.example.php`), never in Git.

Built for PHP 7.4+ and MariaDB 10.3+. No build step; real-time updates use polling.

### Performance notes

The database is on a separate server, so each query costs a network round trip. Queries use emulated
prepares (one round trip each), the busiest requests are batched (phones 5–9 queries, remote 8–11), phones
only write their "last seen" time every few seconds, and the admin session lock is released as soon as a request
is authenticated. Phones poll every 1.5 s, the remote every 1.5 s, and the big screen holds a short request open
(up to 1.5 s, checking every 120 ms) so slide changes appear almost at once. Fonts, the QR library and pdf.js are
served from the app itself (pdf.js only when uploading); `public/assets/.htaccess` lets browsers cache static files.
Set the environment variable `IMASONS_DEBUG=1` to get an `X-Query-Count` header on API responses.
