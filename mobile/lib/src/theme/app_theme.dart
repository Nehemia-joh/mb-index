import 'package:flutter/material.dart';

/// The Maisha Bora (MMRC) design tokens — mirrors the CSS custom properties
/// in includes/ui.php (`gars_styles()`), so the app and the web app share
/// one visual identity: blue bar, gold wordmark, Cormorant headings.
class Brand {
  Brand._();

  // core palette (mmrc.co.tz)
  static const brand = Color(0xFF0B5CAF); // --brand      MMRC blue
  static const brandDeep = Color(0xFF094D94); // --brand-deep  MMRC blue2
  static const brandSoft = Color(0xFFEAF1FB); // --brand-soft  MMRC warm
  static const gold = Color(0xFFFFBA19); // --gold       MMRC gold
  static const goldSoft = Color(0xFFFFE49A); // --gold-soft
  static const paper = Color(0xFFF4F8FF); // --paper      MMRC cream
  static const surface = Color(0xFFFFFFFF); // --surface
  static const ink = Color(0xFF2A2A2A); // --ink        MMRC text
  static const muted = Color(0xFF4D6585); // --muted      MMRC muted
  static const rule = Color(0xFFC8DAF2); // --rule       MMRC sand
  static const ruleStrong = Color(0xFFA8BFE3); // --rule-strong
  static const placeholder = Color(0xFF90A4C4);

  // semantic accents
  static const amber = gold; // --amber      probable / mute
  static const amberSoft = Color(0xFFFEF3C7);
  static const brick = Color(0xFFB3402E); // --brick      missing / very likely
  static const brickSoft = Color(0xFFF9EAE6);
  static const ok = Color(0xFF1F7A4D); // --ok         saved / unlikely
  static const okSoft = Color(0xFFE8F3EC);
  static const level2 = Color(0xFFC05A28); // --orange     Level 2
  static const level3 = Color(0xFFA02C2C); // --red-deep   Level 3

  // role chips, as in the web badges
  static const roleTherapist = brand;
  static const roleAdmin = Color(0xFFB7791F);
  static const roleItAdmin = Color(0xFF41566E);

  static Color roleColor(String role) {
    switch (role) {
      case 'therapist':
        return roleTherapist;
      case 'admin':
        return roleAdmin;
      case 'it_admin':
        return roleItAdmin;
      default:
        return muted;
    }
  }

  static const display = 'CormorantGaramond';
  static const text = 'DMSans';

  /// `0B5CAF`-style wordmark heading, like `.wordmark b` in the web topbar.
  static const wordmarkStyle = TextStyle(
    fontFamily: display,
    fontWeight: FontWeight.w600,
    fontSize: 21,
    height: 1.1,
    color: gold,
  );

  static const wordmarkSubStyle = TextStyle(
    fontFamily: text,
    fontWeight: FontWeight.w400,
    fontSize: 11,
    height: 1.2,
    color: Color(0xCCFFFFFF), // rgba(255,255,255,.8)
  );

  /// Section heading: Cormorant in brand blue over the short gold rule
  /// (`.section h2::after`).
  static TextStyle sectionStyle = const TextStyle(
    fontFamily: display,
    fontWeight: FontWeight.w600,
    fontSize: 22,
    height: 1.15,
    letterSpacing: -0.01,
    color: brand,
  );
}

/// App theme: the web design system translated to ThemeData.
ThemeData buildAppTheme() {
  // Material 3 generates the tonal palette from the MMRC blue; the explicit
  // copyWith below pins the exact brand tokens from includes/ui.php.
  final scheme = ColorScheme.fromSeed(
    seedColor: Brand.brand,
    primary: Brand.brand,
    onPrimary: Brand.gold,
    secondary: Brand.gold,
    onSecondary: Brand.brand,
    tertiary: Brand.ok,
    error: Brand.brick,
    onError: Brand.surface,
    errorContainer: Brand.brickSoft,
    onErrorContainer: Brand.ink,
    surface: Brand.surface,
    onSurface: Brand.ink,
    onSurfaceVariant: Brand.muted,
    outline: Brand.rule,
    outlineVariant: Brand.ruleStrong,
  );

  const inputBorder = OutlineInputBorder(
    borderRadius: BorderRadius.all(Radius.circular(3)),
    borderSide: BorderSide(color: Brand.rule),
  );

  final base = ThemeData(
    useMaterial3: true,
    fontFamily: Brand.text,
    colorScheme: scheme,
    scaffoldBackgroundColor: Brand.paper,
    splashFactory: InkRipple.splashFactory,
  );

  return base.copyWith(
    textTheme: base.textTheme.apply(
      bodyColor: Brand.ink,
      displayColor: Brand.ink,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: Brand.brand,
      foregroundColor: Colors.white,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      shadowColor: Color(0x1F0B5CAF),
      titleTextStyle: Brand.wordmarkStyle,
      iconTheme: IconThemeData(color: Colors.white),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Brand.brandSoft,
      isDense: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 13, vertical: 13),
      hintStyle: const TextStyle(color: Brand.placeholder, fontSize: 15),
      labelStyle: const TextStyle(color: Brand.ink, fontSize: 15),
      floatingLabelStyle: const TextStyle(color: Brand.brand, fontSize: 14),
      border: inputBorder,
      enabledBorder: inputBorder,
      focusedBorder: inputBorder.copyWith(
        borderSide: const BorderSide(color: Brand.brand, width: 1.4),
      ),
      errorBorder: inputBorder.copyWith(
        borderSide: const BorderSide(color: Brand.brick),
      ),
      focusedErrorBorder: inputBorder.copyWith(
        borderSide: const BorderSide(color: Brand.brick, width: 1.4),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Brand.brand,
        foregroundColor: Brand.gold,
        disabledBackgroundColor: Brand.brand.withOpacity(.55),
        disabledForegroundColor: Brand.gold.withOpacity(.75),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 15),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(3)),
        textStyle: const TextStyle(
          fontFamily: Brand.text,
          fontWeight: FontWeight.w700,
          fontSize: 15,
          letterSpacing: .2,
        ),
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: Brand.brand,
        foregroundColor: Brand.gold,
        elevation: 0,
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 15),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(3)),
        textStyle: const TextStyle(
          fontFamily: Brand.text,
          fontWeight: FontWeight.w700,
          fontSize: 15,
        ),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: Brand.brand,
        side: const BorderSide(color: Brand.brand),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(3)),
        textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: Brand.brand,
        textStyle: const TextStyle(fontWeight: FontWeight.w600),
      ),
    ),
    cardTheme: CardTheme(
      color: Brand.surface,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(4),
        side: const BorderSide(color: Brand.rule),
      ),
    ),
    dividerTheme: const DividerThemeData(
      color: Brand.rule,
      thickness: 1,
      space: 1,
    ),
    segmentedButtonTheme: SegmentedButtonThemeData(
      style: ButtonStyle(
        backgroundColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected)
              ? Brand.brand
              : Brand.surface,
        ),
        foregroundColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected)
              ? Brand.gold
              : Brand.muted,
        ),
        side: WidgetStateProperty.all(
          const BorderSide(color: Brand.ruleStrong),
        ),
        textStyle: WidgetStateProperty.all(
          const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
        ),
      ),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Brand.surface,
      surfaceTintColor: Colors.transparent,
      indicatorColor: Brand.brandSoft,
      height: 64,
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      iconTheme: WidgetStateProperty.resolveWith(
        (states) => IconThemeData(
          color: states.contains(WidgetState.selected)
              ? Brand.brand
              : Brand.muted,
          size: 24,
        ),
      ),
      labelTextStyle: WidgetStateProperty.resolveWith(
        (states) => TextStyle(
          fontSize: 12,
          fontWeight: states.contains(WidgetState.selected)
              ? FontWeight.w700
              : FontWeight.w500,
          color: states.contains(WidgetState.selected)
              ? Brand.brand
              : Brand.muted,
        ),
      ),
    ),
    snackBarTheme: const SnackBarThemeData(
      backgroundColor: Brand.ink,
      contentTextStyle: TextStyle(color: Brand.surface, fontSize: 14),
      behavior: SnackBarBehavior.floating,
    ),
    progressIndicatorTheme:
        const ProgressIndicatorThemeData(color: Brand.brand),
    listTileTheme: const ListTileThemeData(
      iconColor: Brand.muted,
      textColor: Brand.ink,
    ),
    dropdownMenuTheme: const DropdownMenuThemeData(
      inputDecorationTheme: InputDecorationTheme(),
    ),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith(
        (states) => states.contains(WidgetState.selected)
            ? Brand.gold
            : Brand.surface,
      ),
      trackColor: WidgetStateProperty.resolveWith(
        (states) => states.contains(WidgetState.selected)
            ? Brand.brand
            : Brand.rule,
      ),
      trackOutlineColor: const WidgetStatePropertyAll(Brand.ruleStrong),
    ),
  );
}

/// The two-line gold wordmark used in every app bar (`.wordmark`).
class BrandWordmark extends StatelessWidget {
  const BrandWordmark({super.key, this.compact = false});

  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        const Text('Maisha Bora', style: Brand.wordmarkStyle),
        if (!compact)
          const Text('Autism Rating Scale, Third Edition',
              style: Brand.wordmarkSubStyle),
      ],
    );
  }
}

/// Section heading with the short gold rule underneath.
class SectionHeading extends StatelessWidget {
  const SectionHeading(this.title, {super.key, this.trailing});

  final String title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(title, style: Brand.sectionStyle),
              ),
              if (trailing != null) trailing!,
            ],
          ),
          const SizedBox(height: 7),
          Container(
            width: 48,
            height: 2,
            color: Brand.gold,
          ),
        ],
      ),
    );
  }
}

/// A `.notice` box from the web app: left rule, tinted background.
enum NoticeKind { info, error, success, warn }

class NoticeBar extends StatelessWidget {
  const NoticeBar(this.message, {super.key, this.kind = NoticeKind.info});

  final String message;
  final NoticeKind kind;

  @override
  Widget build(BuildContext context) {
    final (bg, edge, fg) = switch (kind) {
      NoticeKind.error => (Brand.brickSoft, Brand.brick, Brand.ink),
      NoticeKind.success => (Brand.okSoft, Brand.ok, Brand.ink),
      NoticeKind.warn => (Brand.amberSoft, Brand.amber, Brand.ink),
      NoticeKind.info => (Brand.brandSoft, Brand.brand, Brand.ink),
    };
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(3),
        border: Border(
          left: BorderSide(color: edge, width: 3),
          top: BorderSide(color: edge.withOpacity(.25)),
          right: BorderSide(color: edge.withOpacity(.25)),
          bottom: BorderSide(color: edge.withOpacity(.25)),
        ),
      ),
      child: Text(message,
          style: TextStyle(color: fg, fontSize: 14, height: 1.4)),
    );
  }
}

/// A `.stat` tile: big Cormorant number over a muted label.
class StatTile extends StatelessWidget {
  const StatTile({super.key, required this.value, required this.label});

  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: const BoxDecoration(
        color: Brand.surface,
        border: Border(
          right: BorderSide(color: Brand.rule),
          bottom: BorderSide(color: Brand.rule),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            value,
            style: const TextStyle(
              fontFamily: Brand.display,
              fontWeight: FontWeight.w700,
              fontSize: 30,
              height: 1.1,
              color: Brand.brand,
            ),
          ),
          const SizedBox(height: 3),
          Text(label,
              style: const TextStyle(color: Brand.muted, fontSize: 13)),
        ],
      ),
    );
  }
}

/// Role chip mirroring the web `.badge.<role>` (colour square + label).
class RoleBadge extends StatelessWidget {
  const RoleBadge(this.role, {super.key, this.label});

  final String role;
  final String? label;

  @override
  Widget build(BuildContext context) {
    final color = Brand.roleColor(role);
    final text = label ??
        switch (role) {
          'therapist' => 'Therapist',
          'admin' => 'Admin',
          'it_admin' => 'IT admin',
          _ => role,
        };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: Brand.surface,
        borderRadius: BorderRadius.circular(3),
        border: Border.all(color: Brand.rule),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              color: color,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(width: 7),
          Text(
            text,
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: Brand.ink,
            ),
          ),
        ],
      ),
    );
  }
}
