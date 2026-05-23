import 'package:flutter/material.dart';

/// Tiered ride/post boost (spec §3.2). Keys and prices must mirror the
/// backend's web/server/boost_tiers.php so the wallet charge matches.
class BoostTier {
  final String key;
  final String label;
  final double price; // TND

  const BoostTier(this.key, this.label, this.price);

  /// Price without trailing zeros, e.g. 1.5 → "1.5", 4.0 → "4".
  String get priceLabel {
    final s = price.toStringAsFixed(2);
    return s.replaceAll(RegExp(r'\.?0+$'), '');
  }
}

const List<BoostTier> kBoostTiers = [
  BoostTier('12h', '12 hours', 1.5),
  BoostTier('24h', '24 hours', 2.5),
  BoostTier('48h', '48 hours', 4.0),
  BoostTier('7d', '7 days', 10.0),
];

/// Shows a tier picker dialog and resolves to the chosen tier key
/// ('12h' | '24h' | '48h' | '7d'), or null if the user cancels.
Future<String?> showBoostTierPicker(
  BuildContext context, {
  required String title,
  required String subtitle,
  Color? backgroundColor,
  Color? textColor,
  Color? subtitleColor,
}) {
  final accent = Colors.amber.shade700;
  final fg = textColor ?? Theme.of(context).textTheme.bodyLarge?.color ?? Colors.black87;
  final subFg = subtitleColor ?? fg.withOpacity(0.65);

  return showDialog<String>(
    context: context,
    builder: (ctx) {
      String selected = kBoostTiers.first.key;
      return StatefulBuilder(
        builder: (ctx, setState) => AlertDialog(
          backgroundColor: backgroundColor,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(children: [
            const Icon(Icons.bolt, color: Colors.amber),
            const SizedBox(width: 8),
            Expanded(
              child: Text(title,
                  style: TextStyle(color: fg, fontWeight: FontWeight.w800, fontSize: 18)),
            ),
          ]),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(subtitle, style: TextStyle(color: subFg, fontSize: 13, height: 1.4)),
              const SizedBox(height: 12),
              for (final t in kBoostTiers)
                _TierOption(
                  tier: t,
                  selected: selected == t.key,
                  accent: accent,
                  textColor: fg,
                  onTap: () => setState(() => selected = t.key),
                ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text('Cancel', style: TextStyle(color: subFg)),
            ),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.amber,
                foregroundColor: Colors.black87,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              icon: const Icon(Icons.bolt, size: 16),
              label: const Text('Boost', style: TextStyle(fontWeight: FontWeight.w700)),
              onPressed: () => Navigator.pop(ctx, selected),
            ),
          ],
        ),
      );
    },
  );
}

/// A single selectable tier row — radio-like, without the deprecated Radio API.
class _TierOption extends StatelessWidget {
  final BoostTier tier;
  final bool selected;
  final Color accent;
  final Color textColor;
  final VoidCallback onTap;

  const _TierOption({
    required this.tier,
    required this.selected,
    required this.accent,
    required this.textColor,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
        child: Row(children: [
          Icon(
            selected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
            color: selected ? accent : textColor.withOpacity(0.4),
            size: 20,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(tier.label,
                style: TextStyle(color: textColor, fontWeight: FontWeight.w600)),
          ),
          Text('${tier.priceLabel} DT',
              style: TextStyle(color: accent, fontWeight: FontWeight.w800)),
        ]),
      ),
    );
  }
}
