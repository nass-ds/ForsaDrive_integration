# Screenshots needed for the PFE report

Place all files directly inside `PFE_PROJECT/` (same folder as this file).
The LaTeX `\graphicspath` is set to search both `./img/` and `./` so any
file placed here will be found automatically.

## Already present (no action needed)
| File | Used in |
|------|---------|
| `ForsaDrive_UseCase.png` | chap_03 |
| `ForsaDrive_Sequence_BookRide.png` | chap_03 |
| `ForsaDrive_Sequence_CreateRoute.png` | chap_03 |
| `forsadrive_class_diagram.png` | chap_03 |
| `ForsaDrive_Activity_BookingFlow.png` | chap_03 |
| `forsadrive_scrum.png` | chap_02 |
| `Post covoiturage.png` | chap_02 |
| `aibot.png` | chap_05 (HelpDesk screen) |
| `img/indrive.png` | chap_02 |
| `img/bolt_logo.png` | chap_02 |
| `img/blablacar.png` | chap_02 |
| `img/image.png` | chap_01 (org chart) |

## New diagrams to render (PlantUML → PNG)
Render the following `.puml` files and save as PNG in this folder:

| PUML source | Output PNG name | Used in |
|-------------|-----------------|---------|
| `forsadrive_use_case.puml` | `ForsaDrive_UseCase.png` | chap_02, chap_03 |
| `forsadrive_class_diagram.puml` | `forsadrive_class_diagram.png` | chap_03 |
| `forsadrive_sequence_otp_verification.puml` | `ForsaDrive_Sequence_OtpVerification.png` | chap_03 |
| `forsadrive_sequence_driver_application.puml` | `ForsaDrive_Sequence_DriverApplication.png` | chap_03 |

Render command (if PlantUML JAR is available):
```
java -jar plantuml.jar -tpng forsadrive_use_case.puml
java -jar plantuml.jar -tpng forsadrive_class_diagram.puml
java -jar plantuml.jar -tpng forsadrive_sequence_otp_verification.puml
java -jar plantuml.jar -tpng forsadrive_sequence_driver_application.puml
```

## App screenshots to capture
Take these screenshots from the running Flutter app / web app and save
with exactly the filenames listed below.

### Web application (chap_04 + chap_06)
| Filename | What to capture |
|----------|----------------|
| `web_landing.png` | Landing page (homepage before login) |
| `web_auth.png` | Login or register page |
| `web_dashboard.png` | Home dashboard after login |
| `web_publish_trip.png` | "Offer a ride" form |
| `web_search.png` | Search results page with ride cards |
| `web_booking.png` | Booking confirmation page |
| `web_admin.png` | Admin panel (any tab, e.g. Users or Overview) |

### Mobile application (chap_05 + chap_06)
| Filename | What to capture |
|----------|----------------|
| `flutter_logo.png` | Flutter logo (download from flutter.dev/brand) |
| `mobile_auth.png` | Login or signup screen |
| `mobile_home.png` | Home/dashboard screen with recommendations |
| `mobile_search.png` | Search results with filter chips and match badges |
| `mobile_trip_details.png` | Ride detail screen (with map) |
| `mobile_driver_dashboard.png` | Driver dashboard Analytics tab (arc gauge) |
| `mobile_wallet.png` | Wallet / payments screen |
| `mobile_feed.png` | Social feed screen |
| `mobile_chat.png` | Messaging / chat screen |
| `mobile_profile.png` | Profile / settings screen |

> **Tip:** Use `flutter screenshot` or take a screenshot on the Android
> emulator with Ctrl+S (Android Studio) or the emulator toolbar button.
> For web, use browser DevTools → mobile view and screenshot.
