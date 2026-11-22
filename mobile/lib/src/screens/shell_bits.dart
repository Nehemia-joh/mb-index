import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/session.dart';

/// The shared sign-out confirmation (`.who .out` in the web topbar).
Future<void> confirmLogout(BuildContext context) async {
  final session = context.read<Session>();
  final yes = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Sign out?'),
      content: Text('Signed in as ${session.user?.fullName ?? ''}.'),
      actions: [
        TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel')),
        FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Sign out')),
      ],
    ),
  );
  if (yes == true) await session.logout();
}

/// Which role tab this user gets — mirrors `gars_nav_links()`:
/// it_admin wins over admin, therapist gets its own dashboard.
String? roleTabFor(Set<String> roles) {
  if (roles.contains('it_admin')) return 'it_admin';
  if (roles.contains('admin')) return 'admin';
  if (roles.contains('therapist')) return 'therapist';
  return null;
}

String roleTabLabel(String role) => switch (role) {
      'it_admin' => 'IT admin',
      'admin' => 'Admin',
      'therapist' => 'Dashboard',
      _ => role,
    };
