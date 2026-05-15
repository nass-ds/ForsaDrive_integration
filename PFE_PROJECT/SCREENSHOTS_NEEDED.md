# Screenshots needed for the PFE report

Place all files directly inside `PFE_PROJECT__1_/` (same folder as this file).
The LaTeX `\graphicspath` is set to search both `./img/` and `./` so any
file placed here will be found automatically.

The report is now organized into 5 chapters following Scrum:
- **chap_01** — Project Framework
- **chap_02** — Analysis and Specification of Requirements
- **chap_03** — Sprint 0: Architecture and General Conception
- **chap_04** — Release 1: Sprint 1 (Foundations) + Sprint 2 (Rides & Bookings)
- **chap_05** — Release 2: Sprint 3 (Payments & Intelligence) + Sprint 4 (Community & Finalization) + Tests + Deployment

## Already present (no action needed)
| File | Used in |
|------|---------|
| `ForsaDrive_UseCase.png` | chap_03 |
| `ForsaDrive_Sequence_BookRide.png` | chap_04 (Sprint 2) |
| `ForsaDrive_Sequence_CreateRoute.png` | chap_04 (Sprint 2) |
| `ForsaDrive_Sequence_VerifyStudent.png` | chap_04 (Sprint 1) |
| `ForsaDrive_Sequence_DriverApplication.png` | chap_04 (Sprint 1) |
| `ForsaDrive_Activity_BookingFlow.png` | chap_04 (Sprint 2) |
| `forsadrive_class_diagram.png` | chap_03 |
| `forsadrive_scrum.png` | chap_02 |
| `Post covoiturage.png` | chap_02 |
| `aibot.png` | chap_05 (HelpDesk screen) |
| `img/indrive.png` | chap_02 |
| `img/bolt_logo.png` | chap_02 |
| `img/blablacar.png` | chap_02 |
| `image.png` | chap_01 (org chart) |

## App screenshots still to capture

Take these screenshots from the running Flutter app / web app and save
with exactly the filenames listed below.

### Web application
| Filename | Used in | What to capture |
|----------|---------|----------------|
| `web_landing.png` | chap_04 (Sprint 1) | Landing page (before login) |
| `web_auth.png` | chap_04 (Sprint 1) | Login or register page |
| `web_publish_trip.png` | chap_04 (Sprint 2) | "Offer a ride" form |
| `web_search.png` | chap_04 (Sprint 2) | Search results page with ride cards |
| `web_booking.png` | chap_04 (Sprint 2) | Booking confirmation page |
| `web_admin.png` | chap_05 (Sprint 4) | Admin panel (any tab) |

### Mobile application
| Filename | Used in | What to capture |
|----------|---------|----------------|
| `mobile_auth.png` | chap_04 (Sprint 1) | Login or signup screen |
| `mobile_profile.png` | chap_04 (Sprint 1) | Profile screen with student badge |
| `mobile_home.png` | chap_04 (Sprint 2) | Home screen with personalized sections |
| `mobile_search.png` | chap_04 (Sprint 2) | Search results with filter chips and match badges |
| `mobile_trip_details.png` | chap_04 (Sprint 2) | Ride detail screen (with map) |
| `mobile_wallet.png` | chap_05 (Sprint 3) | Wallet / payments screen |
| `mobile_driver_dashboard.png` | chap_05 (Sprint 3) | Driver dashboard Analytics tab |
| `mobile_chat.png` | chap_05 (Sprint 4) | Messaging / chat screen |
| `mobile_feed.png` | chap_05 (Sprint 4) | Social feed screen |

> **Tip:** Use `flutter screenshot` or take a screenshot on the Android
> emulator with Ctrl+S (Android Studio) or the emulator toolbar button.
> For web, use browser DevTools → mobile view and screenshot.
