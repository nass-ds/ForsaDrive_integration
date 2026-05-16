# ForsaDrive — Final Year Project (PFE) deliverables

Final-year project for the 2025 — 2026 academic year. Carried out at
**ATOMIC IT** under the supervision of Mr. Khalil SELMI (company) and
Ms. Ines BEN NASR (academic).

**Team**: Youssef BEN ABID & Anas YOUNES

---

## What's in this folder

| File / folder | Purpose | Status |
|---|---|---|
| `main.tex` | LaTeX entry point. Compile this to get the report PDF. | ✅ |
| `chap_01.tex` | **Chapter 1** — Project Framework (ATOMIC IT, mobility context, motivation). | ✅ Supervisor-approved |
| `chap_02.tex` | **Chapter 2** — Analysis & Specification (existing solutions, Scrum, actors, requirements). | ✅ Supervisor-approved |
| `chap_03.tex` | **Chapter 3** — Sprint 0: Architecture & General Conception. | ✅ Rewritten in Scrum format |
| `chap_04.tex` | **Chapter 4** — Release 1: Sprint 1 (Foundations) + Sprint 2 (Rides & Bookings). | ✅ Rewritten in Scrum format |
| `chap_05.tex` | **Chapter 5** — Release 2: Sprint 3 (Payments & Intelligence) + Sprint 4 (Community & Finalization) + Tests + Deployment. | ✅ Rewritten in Scrum format |
| `introduction.tex` | General introduction (5-chapter outline). | ✅ |
| `conclusion.tex` | General conclusion (achievements, limitations, roadmap). | ✅ |
| `annexe.tex` | Annexes (backlog extract, API endpoints, Postman, DB schema). | ✅ |
| `biblio.bib` | BibTeX bibliography. | ✅ |
| `global_config.tex` | Project metadata (title, authors, supervisors, abstracts FR/EN/AR). | ✅ |
| `tpl/` | ISI LaTeX template (`isipfe.cls`, cover page, etc.). | unchanged |
| `*.png`, `*.puml` | Diagrams (UML use case, class diagram, sequence diagrams, activity diagram, Scrum framework, etc.). | ✅ |
| `img/` | Logos of competing solutions (BlaBlaCar, inDrive, Bolt) and company logo. | ✅ |
| **`ForsaDrive_Defense.pptx`** | **Defense presentation (21 slides, 16:9, ~17 min + Q&A).** Includes embedded speaker notes visible in PowerPoint's presenter view. | ✅ |
| `ForsaDrive_Defense.pdf` | PDF export of the defense deck — fallback if PowerPoint is unavailable on the projector. | ✅ |
| `generate_presentation.py` | Source script that builds the .pptx. Re-run after any change. | ✅ |
| `PRESENTATION_NOTES.md` | Defense prep guide: per-slide talking points, demo script, anticipated jury Q&A, pre-defense checklist. | ✅ |
| `SCREENSHOTS_NEEDED.md` | List of app screenshots still to capture for the report + deck. | ⏳ in progress |

---

## Report structure (Scrum-aligned, 5 chapters)

```
┌───────────────────────────────────────────────────────────────────┐
│  Chapter 1 — Project Framework                                    │
│      ATOMIC IT · Mobility in Tunisia · Problem · Objectives        │
├───────────────────────────────────────────────────────────────────┤
│  Chapter 2 — Analysis & Specification                             │
│      Existing solutions · Scrum · Actors · Requirements · UC       │
├───────────────────────────────────────────────────────────────────┤
│  Chapter 3 — Sprint 0: Architecture & General Conception          │
│      Sprint 0 backlog · Architecture · Class diagram · Schema     │
├───────────────────────────────────────────────────────────────────┤
│  Chapter 4 — Release 1                                            │
│      Dev environment · Stack · Sprint 1 · Sprint 2                │
│      (each sprint: backlog → conception → realization → review)   │
├───────────────────────────────────────────────────────────────────┤
│  Chapter 5 — Release 2                                            │
│      Sprint 3 · Sprint 4 · Testing · Deployment                   │
└───────────────────────────────────────────────────────────────────┘
```

---

## How to compile the report

```bash
# Inside this folder:
pdflatex main.tex
biber main           # bibliography
pdflatex main.tex    # second pass for refs
pdflatex main.tex    # third pass for ToC

# Or use latexmk for a single command:
latexmk -pdf main.tex
```

**Local note (Linux)**: the template requires the babel `arabic`
language data. Install it with `sudo apt install texlive-lang-arabic`
if you see `Unknown option 'arabic'`. On Overleaf, it works out of the
box.

---

## How to (re)build the defense presentation

The .pptx is regenerated from a Python script — keep that as the
source of truth, edit the script rather than the file directly.

```bash
# Install python-pptx once (only needed locally):
pip install --user --break-system-packages python-pptx

# From inside this folder:
python3 generate_presentation.py

# (Optional) Export to PDF as a projector fallback:
libreoffice --headless --convert-to pdf ForsaDrive_Defense.pptx
```

The script reads diagrams and screenshots from this same folder
(`forsadrive_class_diagram.png`, `ForsaDrive_UseCase.png`, sequence
diagrams, `mobile_*.png`, `web_*.png`). Anything missing is replaced
by a grey placeholder labelled with the expected filename.

---

## Related code repositories

This `PFE_PROJECT/` folder is the **report and presentation** of the
project. The actual application code lives next to it in the same
monorepo:

| Folder | Purpose |
|---|---|
| `web/` | PHP backend (REST API under `/api/`) and server-rendered web UI. SQLite (WAL mode) as the database. |
| `mobile/` | Flutter cross-platform mobile application. Consumes the same REST API as the web UI. |

The monorepo is published to three Git remotes:
- **`nass-ds/ForsaDrive_integration`** — the team's main integration repository.
- **`Youssef-Ben-Abid/ForsaDrive`** — personal copy of the web side.
- **`Youssef-Ben-Abid/ForsaDrive_PFE`** — personal copy of the full project (an old Dart-backed prototype is archived on the branch `archive/dart-version`).

---

## Acknowledgements

- LaTeX template: **ISI** ([stoufa/ISI-LaTeX-Template](https://github.com/stoufa/ISI-LaTeX-Template))
  by Med HEDHILI (April 2016), maintained by Mustapha SAHLI (Nov 2017).
- Diagrams produced with PlantUML.
- Brand color palette derived from the mobile app theme
  (`mobile/lib/utils/app_theme.dart` — navy `#0A1628`, gold `#E8B84B`).
