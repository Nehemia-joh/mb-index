import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../state/session.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _url = TextEditingController(text: ApiClient.defaultBaseUrl);
  final _username = TextEditingController();
  final _password = TextEditingController();
  bool _showServer = false;
  bool _urlSeeded = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // show the address restored from storage (or the last one the user typed)
    // instead of the emulator default — the login screen only mounts after
    // Session.restore() has finished.
    if (!_urlSeeded) {
      _urlSeeded = true;
      final saved = context.read<Session>().client.baseUrl.trim();
      if (saved.isNotEmpty) _url.text = saved;
    }
  }

  @override
  void dispose() {
    _url.dispose();
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final session = context.read<Session>();
    final url = _url.text.trim();
    if (url.isEmpty || _username.text.trim().isEmpty || _password.text.isEmpty) {
      session.error = 'Server address, username and password are required.';
      setState(() {});
      return;
    }
    // point the client at this server before attempting login
    session.client.baseUrl = url;
    await session.store.saveBaseUrl(url); // survives a failed attempt
    if (!mounted) return;
    final ok =
        await session.login(url, _username.text.trim(), _password.text);
    if (!mounted) return;
    if (!ok) setState(() {}); // error is on the session
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final theme = Theme.of(context);

    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Icon(Icons.psychology_alt,
                    size: 56, color: theme.colorScheme.primary),
                const SizedBox(height: 8),
                Text(
                  'Maisha Bora',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.headlineMedium
                      ?.copyWith(fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 4),
                Text(
                  'GARS-3 assessments and records',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 24),
                if (session.error != null) ...[
                  MaterialBanner(
                    backgroundColor:
                        theme.colorScheme.errorContainer,
                    content: Text(session.error!,
                        style:
                            TextStyle(color: theme.colorScheme.onErrorContainer)),
                    actions: const [SizedBox.shrink()],
                  ),
                  const SizedBox(height: 12),
                ],
                TextField(
                  controller: _username,
                  autofillHints: const [AutofillHints.username],
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
                const SizedBox(height: 12),
                ExpansionTile(
                  title: const Text('Server settings',
                      style: TextStyle(fontSize: 14)),
                  subtitle: Text(_url.text, style: const TextStyle(fontSize: 12)),
                  initiallyExpanded: _showServer,
                  onExpansionChanged: (v) => setState(() => _showServer = v),
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                      child: TextField(
                        controller: _url,
                        keyboardType: TextInputType.url,
                        decoration: const InputDecoration(
                          labelText: 'API base URL',
                          hintText: 'http://10.0.2.2:8123/api/v1',
                          helperText:
                              'Emulator uses 10.0.2.2; a physical phone needs '
                              "the server's LAN IP (e.g. 192.168.0.168).",
                          helperMaxLines: 3,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: session.busy ? null : _submit,
                  child: session.busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Sign in'),
                ),
                const SizedBox(height: 12),
                Text(
                  'Accounts are created by your Administrator.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
