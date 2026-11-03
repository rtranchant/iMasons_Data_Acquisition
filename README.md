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
   swipe right = Yes, left = No, down = Don't know (buttons and arrow keys too). A statement stops being
   asked once it has the minimum number of answers (default 8) and enough agreement (default 75%)
   on Yes or No. Otherwise it is flagged *Needs revision*. Rewording a statement restarts its count.
2. **Text input** – slides: *Live statements*, *Statement wall*. Optional multiple-choice questions.
   Statements appear on screen only after approval on the remote.
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

On the big screen, slides fill the screen. When the host is logged in on the laptop running the screen,
a click or →/Space/PageDown moves forward and ← /PageUp moves back (presenter clickers work too).
Hide a slide (e.g. a placeholder) and Next/Previous skip it.

Hiding a *Live participation* slide also hides that module's results slide; Next/Previous skip hidden slides.

## Deployment

Push to `main` → Plesk pulls → live at `/imasons_session_running_platform/public/`.
Database migrations in `src/schema.php` run automatically on the next page load.
Server settings live in `imasons_config.php` (see `config/imasons_config.example.php`), never in Git.

Built for PHP 7.4+ and MariaDB 10.3+. No build step; real-time updates use polling.
