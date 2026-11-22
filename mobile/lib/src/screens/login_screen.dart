import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/session.dart';
import '../theme/app_theme.dart';

/// Sign-in screen: the field-session photograph (static/wallpaper.jpg) as a
/// full-bleed backdrop under a soft brand scrim, with the form on a card.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _username = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final session = context.read<Session>();
    if (_username.text.trim().isEmpty || _password.text.isEmpty) {
      session.error = 'Username and password are required.';
      setState(() {});
      return;
    }
    final ok = await session.login(_username.text.trim(), _password.text);
    if (!mounted) return;
    if (!ok) setState(() {}); // error is on the session
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();

    return Scaffold(
      body: Stack(
        fit: StackFit.expand,
        children: [
          // — the photograph —
          Image.asset(
            'assets/bg/wallpaper.jpg',
            fit: BoxFit.cover,
            filterQuality: FilterQuality.medium,
          ),
          // — brand scrim so the form stays readable over any part of it —
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                stops: [0, .38, .72, 1],
                colors: [
                  Color(0xE6094D94), // brand-deep over the top of the photo
                  Color(0x590B5CAF),
                  Color(0xB3F4F8FF),
                  Color(0xF2F4F8FF),
                ],
              ),
            ),
          ),
          // — the form —
          Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Center(
                      child: Image.asset(
                        'assets/icon/mb-mark.png',
                        width: 76,
                        height: 76,
                        filterQuality: FilterQuality.medium,
                      ),
                    ),
                    const SizedBox(height: 14),
                    const Text(
                      'Maisha Bora',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontFamily: Brand.display,
                        fontWeight: FontWeight.w600,
                        fontSize: 40,
                        height: 1.05,
                        color: Brand.gold,
                        shadows: [
                          Shadow(color: Color(0x80002A55), blurRadius: 12),
                        ],
                      ),
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'Autism Rating Scale, Third Edition',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 14,
                        fontWeight: FontWeight.w500,
                        shadows: [
                          Shadow(color: Color(0x99002A55), blurRadius: 8),
                        ],
                      ),
                    ),
                    const SizedBox(height: 26),
                    Container(
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: Brand.surface.withOpacity(.96),
                        borderRadius: BorderRadius.circular(4),
                        border: Border.all(color: Brand.rule),
                        boxShadow: const [
                          BoxShadow(
                            color: Color(0x330B5CAF),
                            blurRadius: 24,
                            offset: Offset(0, 8),
                          ),
                        ],
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          if (session.error != null) ...[
                            NoticeBar(session.error!, kind: NoticeKind.error),
                            const SizedBox(height: 14),
                          ],
                          TextField(
                            controller: _username,
                            autofillHints: const [AutofillHints.username],
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              labelText: 'Username',
                              prefixIcon: Icon(Icons.person_outline),
                            ),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _password,
                            obscureText: true,
                            autofillHints: const [AutofillHints.password],
                            onSubmitted: (_) => _submit(),
                            decoration: const InputDecoration(
                              labelText: 'Password',
                              prefixIcon: Icon(Icons.lock_outline),
                            ),
                          ),
                          const SizedBox(height: 20),
                          FilledButton(
                            onPressed: session.busy ? null : _submit,
                            child: session.busy
                                ? const SizedBox(
                                    height: 20,
                                    width: 20,
                                    child:
                                        CircularProgressIndicator(strokeWidth: 2),
                                  )
                                : const Text('Sign in'),
                          ),
                          const SizedBox(height: 14),
                          const Text(
                            'Every assessment you complete helps a child '
                            'and their family take the next step forward.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: Brand.muted,
                              fontSize: 13,
                              height: 1.45,
                              fontStyle: FontStyle.italic,
                            ),
                          ),
                          const SizedBox(height: 8),
                          const Text(
                            'Thank you for the work you do. 🌱',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: Brand.brand,
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
