import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

class AppLocalizations {
  final Locale locale;
  const AppLocalizations(this.locale);

  static AppLocalizations of(BuildContext context) =>
      Localizations.of<AppLocalizations>(context, AppLocalizations)!;

  static const delegate = _AppLocalizationsDelegate();

  static const supportedLocales = [
    Locale('fr'),
    Locale('en'),
    Locale('ar'),
  ];

  String _t(String fr, String en, String ar) {
    switch (locale.languageCode) {
      case 'ar':
        return ar;
      case 'en':
        return en;
      default:
        return fr;
    }
  }

  // ── App ─────────────────────────────────────────────────────────────────────
  String get appName => 'ForsaDrive';
  String get appTagline => _t(
        "Plateforme N°1 de covoiturage en Tunisie",
        "Tunisia's #1 Ride-Sharing Platform",
        "منصة مشاركة الركوب الأولى في تونس",
      );

  // ── Common ──────────────────────────────────────────────────────────────────
  String get ok => _t('OK', 'OK', 'موافق');
  String get cancel => _t('Annuler', 'Cancel', 'إلغاء');
  String get save => _t('Enregistrer', 'Save', 'حفظ');
  String get edit => _t('Modifier', 'Edit', 'تعديل');
  String get confirm => _t('Confirmer', 'Confirm', 'تأكيد');
  String get loading => _t('Chargement...', 'Loading...', 'جاري التحميل...');
  String get required => _t('Requis', 'Required', 'مطلوب');
  String get all => _t('Tous', 'All', 'الكل');
  String get error => _t('Erreur', 'Error', 'خطأ');

  // ── Greetings ────────────────────────────────────────────────────────────────
  String get goodMorning => _t('Bonjour', 'Good morning', 'صباح الخير');
  String get goodAfternoon =>
      _t('Bon après-midi', 'Good afternoon', 'مساء الخير');
  String get goodEvening => _t('Bonsoir', 'Good evening', 'مساء النور');

  // ── Home / Landing ───────────────────────────────────────────────────────────
  String get whereAreYouHeading =>
      _t('Où allez-\nvous ?', 'Where are\nyou heading?', 'إلى أين\nتتجه؟');
  String get searchDestination => _t(
        'Rechercher une destination…',
        'Search destination…',
        'ابحث عن وجهتك...',
      );
  String get availableRides =>
      _t('Trajets disponibles', 'Available Rides', 'الرحلات المتاحة');
  String get seeAll => _t('Voir tout →', 'See all →', '← الكل');
  String get noRidesAvailable =>
      _t('Aucun trajet disponible', 'No rides available', 'لا توجد رحلات متاحة');
  String get tryDifferentRoute => _t(
        'Essayez une autre route ou revenez plus tard',
        'Try a different route or check back later',
        'جرّب مساراً آخر أو تحقق لاحقاً',
      );
  String get searchAllRides =>
      _t('Voir tous les trajets', 'Search all rides', 'البحث عن جميع الرحلات');
  String get tunisLocation =>
      _t('Tunis, Tunisie', 'Tunis, Tunisia', 'تونس، تونس');

  // ── Quick Actions ────────────────────────────────────────────────────────────
  String get findRide =>
      _t('Trouver\ntrajet', 'Find\nRide', 'ابحث\nعن رحلة');
  String get offerRide =>
      _t('Proposer\ntrajet', 'Offer\nRide', 'اعرض\nرحلة');
  String get myTrips => _t('Mes\ntrajets', 'My\nTrips', 'رحلاتي');
  String get payments => _t('Paiements', 'Payments', 'المدفوعات');

  // ── Stats ────────────────────────────────────────────────────────────────────
  String get statDrivers =>
      _t('Conducteurs', 'Drivers', 'السائقون');
  String get statOnTime => _t("À l'heure", 'On Time', 'في الوقت');
  String get statRating => _t('Note', 'Rating', 'التقييم');
  String get statRides => _t('Trajets', 'Rides', 'الرحلات');

  // ── Ride Card ────────────────────────────────────────────────────────────────
  String get perSeat => _t('par siège', 'per seat', 'للمقعد');
  String get book => _t('Réserver', 'Book', 'احجز');
  String get departure => _t('Départ', 'Departure', 'المغادرة');
  String get arrival => _t('Arrivée', 'Arrival', 'الوصول');
  String seatsLeft(int n) => _t('$n restants', '$n left', '$n متبقٍ');

  // ── Navigation ───────────────────────────────────────────────────────────────
  String get home => _t('Accueil', 'Home', 'الرئيسية');
  String get search => _t('Recherche', 'Search', 'بحث');
  String get rides => _t('Trajets', 'Rides', 'الرحلات');
  String get account => _t('Compte', 'Account', 'الحساب');
  String get dashboard =>
      _t('Tableau de bord', 'Dashboard', 'لوحة التحكم');
  String get editProfile =>
      _t('Modifier le profil', 'Edit Profile', 'تعديل الملف');
  String get logout => _t('Déconnexion', 'Logout', 'تسجيل الخروج');

  // ── Auth — Login ─────────────────────────────────────────────────────────────
  String get welcomeBack => _t('Bon retour', 'Welcome back', 'مرحباً بعودتك');
  String get signInToContinue => _t(
        'Connectez-vous pour continuer',
        'Sign in to continue your journey',
        'سجّل دخولك لمواصلة رحلتك',
      );
  String get emailAddress =>
      _t('Adresse email', 'Email address', 'البريد الإلكتروني');
  String get password => _t('Mot de passe', 'Password', 'كلمة المرور');
  String get signIn => _t('Connexion', 'Sign In', 'تسجيل الدخول');
  String get forgotPassword =>
      _t('Mot de passe oublié ?', 'Forgot password?', 'نسيت كلمة المرور؟');
  String get orContinueWith =>
      _t('ou continuer avec', 'or continue with', 'أو تابع بـ');
  String get dontHaveAccount =>
      _t("Pas encore de compte ?", "Don't have an account?", 'ليس لديك حساب؟');
  String get signUpFree =>
      _t("S'inscrire gratuitement", 'Sign up free', 'سجّل مجاناً');
  String get validEmailRequired => _t(
        'Email valide requis',
        'Valid email required',
        'يرجى إدخال بريد إلكتروني صحيح',
      );
  String get passwordRequired =>
      _t('Mot de passe requis', 'Password required', 'كلمة المرور مطلوبة');

  // ── Auth — Sign Up ───────────────────────────────────────────────────────────
  String get createAccount =>
      _t('Créer un compte', 'Create Account', 'إنشاء حساب');
  String get joinForsaDrive =>
      _t('Rejoignez ForsaDrive', 'Join ForsaDrive', 'انضم إلى ForsaDrive');
  String get username =>
      _t("Nom d'utilisateur", 'Username', 'اسم المستخدم');
  String get confirmPassword =>
      _t('Confirmer le mot de passe', 'Confirm Password', 'تأكيد كلمة المرور');
  String get iAmA => _t('Je suis un(e)', 'I am a', 'أنا');
  String get passenger => _t('Passager', 'Passenger', 'راكب');
  String get driver => _t('Conducteur', 'Driver', 'سائق');
  String get alreadyHaveAccount =>
      _t('Déjà un compte ?', 'Already have an account?', 'لديك حساب بالفعل؟');
  String get logIn => _t('Se connecter', 'Log in', 'تسجيل الدخول');

  // ── Settings ─────────────────────────────────────────────────────────────────
  String get settings => _t('Paramètres', 'Settings', 'الإعدادات');
  String get profileTab => _t('Profil', 'Profile', 'الملف الشخصي');
  String get passwordTab => _t('Mot de passe', 'Password', 'كلمة المرور');
  String get phone => _t('Téléphone', 'Phone', 'الهاتف');
  String get bio => _t('Bio', 'Bio', 'نبذة');
  String get gender => _t('Genre', 'Gender', 'الجنس');
  String get governorate => _t('Gouvernorat', 'Governorate', 'الولاية');
  String get saveChanges =>
      _t('Enregistrer les modifications', 'Save Changes', 'حفظ التغييرات');
  String get male => _t('Homme', 'Male', 'ذكر');
  String get female => _t('Femme', 'Female', 'أنثى');
  String get other => _t('Autre', 'Other', 'آخر');

  // Driver
  String get becomeADriver =>
      _t('Devenir conducteur', 'Become a Driver', 'كن سائقاً');
  String get earnMoneyByOfferingRides => _t(
        "Gagnez de l'argent en proposant des trajets",
        'Earn money by offering rides',
        'اكسب المال من خلال تقديم الرحلات',
      );
  String get enable => _t('Activer', 'Enable', 'تفعيل');
  String get youAreNowADriver => _t(
        'Vous êtes maintenant conducteur !',
        'You are now a driver!',
        'أنت الآن سائق!',
      );

  // Student
  String get verifyStudentStatus => _t(
        'Vérifier le statut étudiant',
        'Verify Student Status',
        'التحقق من الطالب',
      );
  String get studentVerified =>
      _t('Étudiant vérifié ✓', 'Student Verified ✓', 'طالب موثق ✓');
  String get fiftyPctDiscountActive => _t(
        'Réduction 50% active sur tous les trajets',
        '50% discount active on all rides',
        'خصم 50% نشط على جميع الرحلات',
      );
  String get getFiftyPctOff => _t(
        'Obtenez 50% de réduction avec votre email universitaire',
        'Get 50% off with your university email',
        'احصل على خصم 50% بريدك الجامعي',
      );
  String get active => _t('Actif', 'Active', 'نشط');
  String get verify => _t('Vérifier', 'Verify', 'تحقق');

  // Password change
  String get changePasswordTitle =>
      _t('Changer le mot de passe', 'Change Password', 'تغيير كلمة المرور');
  String get currentPassword =>
      _t('Mot de passe actuel', 'Current Password', 'كلمة المرور الحالية');
  String get newPassword =>
      _t('Nouveau mot de passe', 'New Password', 'كلمة المرور الجديدة');
  String get confirmNewPassword => _t(
        'Confirmer le nouveau mot de passe',
        'Confirm New Password',
        'تأكيد كلمة المرور الجديدة',
      );
  String get updatePassword => _t(
        'Mettre à jour le mot de passe',
        'Update Password',
        'تحديث كلمة المرور',
      );
  String get passwordsDoNotMatch => _t(
        'Les mots de passe ne correspondent pas',
        'Passwords do not match',
        'كلمتا المرور غير متطابقتين',
      );
  String minChars(int n) =>
      _t('Min $n caractères', 'Min $n characters', 'الحد الأدنى $n أحرف');

  // Snackbars
  String get profileUpdated => _t(
        'Profil mis à jour !',
        'Profile updated!',
        'تم تحديث الملف الشخصي!',
      );
  String get passwordChanged => _t(
        'Mot de passe modifié !',
        'Password changed!',
        'تم تغيير كلمة المرور!',
      );

  // ── Dashboard ────────────────────────────────────────────────────────────────
  String get balance => _t('Solde', 'Balance', 'الرصيد');
  String get findARide => _t('Trouver un trajet', 'Find a Ride', 'ابحث عن رحلة');
  String get myRides => _t('Mes trajets', 'My Rides', 'رحلاتي');
  String get bookingRequests =>
      _t('Demandes de réservation', 'Booking Requests', 'طلبات الحجز');
  String get findAvailableRides => _t(
        'Trouver des trajets disponibles',
        'Find Available Rides',
        'ابحث عن رحلات متاحة',
      );
  String get filters => _t('Filtres', 'Filters', 'الفلاتر');
  String get from => _t('De', 'From', 'من');
  String get to => _t('À', 'To', 'إلى');
  String get noRidesFound =>
      _t('Aucun trajet trouvé.', 'No rides found.', 'لم يتم العثور على رحلات.');
  String get clearFilters =>
      _t('Effacer les filtres', 'Clear filters', 'مسح الفلاتر');
  String get bookRide =>
      _t('Réserver un trajet', 'Book Ride', 'حجز رحلة');
  String bookConfirmMsg(String from, String to) => _t(
        'Réserver de $from à $to ?',
        'Book from $from to $to?',
        'هل تريد الحجز من $from إلى $to؟',
      );
  String chargedMsg(String amount) => _t(
        'Vous serez facturé $amount TND (50% à l\'avance).',
        'You\'ll be charged $amount TND (50% upfront).',
        'سيتم تحصيل $amount TND (50% مقدماً).',
      );
  String get bookingSent => _t(
        'Réservation envoyée ! En attente de confirmation du conducteur.',
        'Booking sent! Waiting for driver confirmation.',
        'تم إرسال طلب الحجز! بانتظار تأكيد السائق.',
      );

  // ── Advanced Filters ─────────────────────────────────────────────────────────
  String get advancedFilters =>
      _t('Filtres avancés', 'Advanced Filters', 'فلاتر متقدمة');
  String get reset =>
      _t('Réinitialiser', 'Reset', 'إعادة تعيين');
  String get priceRange =>
      _t('Fourchette de prix', 'Price Range', 'نطاق السعر');
  String get price => _t('Prix', 'Price', 'السعر');
  String get minDriverRating => _t(
        'Note minimale conducteur',
        'Min Driver Rating',
        'أقل تقييم للسائق',
      );
  String get any => _t('Tous', 'Any', 'أي');
  String get rating => _t('Note', 'Rating', 'التقييم');
  String get date => _t('Date', 'Date', 'التاريخ');
  String get seats => _t('Sièges', 'Seats', 'المقاعد');
  String get amenities => _t('Commodités', 'Amenities', 'وسائل الراحة');
  String get amenityAc => _t('Climatisation', 'A/C', 'تكييف');
  String get amenityWifi => _t('WiFi', 'WiFi', 'واي فاي');
  String get amenityMusic => _t('Musique', 'Music', 'موسيقى');
  String get amenityAccessible =>
      _t('Accessibilité', 'Accessible', 'للمعاقين');
  String get sortBy => _t('Trier par', 'Sort by', 'ترتيب حسب');
  String get defaultSort => _t('Par défaut', 'Default', 'الافتراضي');
  String get applyFilters =>
      _t('Appliquer les filtres', 'Apply Filters', 'تطبيق الفلاتر');

  // ── Language picker ──────────────────────────────────────────────────────────
  String get language => _t('Langue', 'Language', 'اللغة');
  String get selectLanguage =>
      _t('Sélectionnez votre langue', 'Select your language', 'اختر لغتك');
  String get french => _t('Français', 'French', 'الفرنسية');
  String get english => _t('Anglais', 'English', 'الإنجليزية');
  String get arabic => _t('Arabe', 'Arabic', 'العربية');
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  bool isSupported(Locale locale) =>
      ['fr', 'en', 'ar'].contains(locale.languageCode);

  @override
  Future<AppLocalizations> load(Locale locale) =>
      SynchronousFuture(AppLocalizations(locale));

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}